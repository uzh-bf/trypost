<?php

declare(strict_types=1);

use App\Support\Media\FlatBlobAdapter;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\Visibility;

beforeEach(function (): void {
    $this->blobRoot = sys_get_temp_dir().'/trypost-flat-blob-'.bin2hex(random_bytes(8));
    mkdir($this->blobRoot, 0777, true);
    $this->inner = new LocalFilesystemAdapter($this->blobRoot, lazyRootCreation: true);
    $this->adapter = new FlatBlobAdapter($this->inner);
    $this->config = new Config;
});

afterEach(function (): void {
    $remove = static function (string $path) use (&$remove): void {
        if (is_file($path) || is_link($path)) {
            unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $remove($path.'/'.$entry);
            }
        }

        rmdir($path);
    };

    $remove($this->blobRoot);
});

test('encoding and decoding logical paths is reversible and rejects ambiguous inputs', function (): void {
    expect(FlatBlobAdapter::encodePath('/media/2026//photo.jpg'))->toBe('media~2026~photo.jpg')
        ->and(FlatBlobAdapter::decodePath('media~2026~photo.jpg'))->toBe('media/2026/photo.jpg')
        ->and(FlatBlobAdapter::encodePath('portrait.jpg'))->toBe('portrait.jpg');
});

test('invalid logical and physical paths are refused', function (string $path): void {
    expect(fn () => FlatBlobAdapter::encodePath($path))->toThrow(InvalidArgumentException::class);
})->with([
    'empty' => '',
    'separator only' => '/',
    'reserved separator' => 'media/photo~1.jpg',
    'traversal' => 'media/../photo.jpg',
    'dot segment' => 'media/./photo.jpg',
]);

test('physical paths with non-inverse separators are refused', function (string $path): void {
    expect(fn () => FlatBlobAdapter::decodePath($path))->toThrow(InvalidArgumentException::class);
})->with([
    'empty' => '',
    'hierarchical' => 'media/photo.jpg',
    'leading separator' => '~media~photo.jpg',
    'trailing separator' => 'media~photo.jpg~',
    'empty segment' => 'media~~photo.jpg',
    'traversal segment' => 'media~..~photo.jpg',
]);

test('file operations map to flat names and decode metadata paths', function (): void {
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);

    expect($png)->toBeString();

    $this->adapter->write('media/source.png', $png, new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));

    expect($this->adapter->fileExists('media/source.png'))->toBeTrue()
        ->and($this->inner->fileExists('media~source.png'))->toBeTrue()
        ->and($this->inner->fileExists('media/source.png'))->toBeFalse()
        ->and($this->adapter->read('media/source.png'))->toBe($png);

    $mime = $this->adapter->mimeType('media/source.png');
    $size = $this->adapter->fileSize('media/source.png');
    $modified = $this->adapter->lastModified('media/source.png');
    $visibility = $this->adapter->visibility('media/source.png');

    expect($mime->path())->toBe('media/source.png')
        ->and($mime->mimeType())->toBe('image/png')
        ->and($size->path())->toBe('media/source.png')
        ->and($size->fileSize())->toBe(strlen($png))
        ->and($modified->path())->toBe('media/source.png')
        ->and($modified->lastModified())->toBeInt()
        ->and($visibility->path())->toBe('media/source.png')
        ->and($visibility->visibility())->toBe(Visibility::PUBLIC);

    $stream = fopen('php://temp', 'w+b');
    fwrite($stream, 'stream payload');
    rewind($stream);
    $this->adapter->writeStream('media/stream.bin', $stream, $this->config);
    fclose($stream);

    $readStream = $this->adapter->readStream('media/stream.bin');
    $streamContents = stream_get_contents($readStream);
    fclose($readStream);

    expect($streamContents)->toBe('stream payload')
        ->and($this->inner->fileExists('media~stream.bin'))->toBeTrue();

    $this->adapter->copy('media/source.png', 'media/copied.png', $this->config);
    $this->adapter->move('media/copied.png', 'media/moved.png', $this->config);

    expect($this->adapter->fileExists('media/source.png'))->toBeTrue()
        ->and($this->adapter->fileExists('media/copied.png'))->toBeFalse()
        ->and($this->adapter->fileExists('media/moved.png'))->toBeTrue()
        ->and($this->inner->fileExists('media~moved.png'))->toBeTrue();

    $this->adapter->setVisibility('media/moved.png', Visibility::PRIVATE);

    expect($this->adapter->visibility('media/moved.png')->visibility())->toBe(Visibility::PRIVATE);

    $this->adapter->delete('media/stream.bin');

    expect($this->adapter->fileExists('media/stream.bin'))->toBeFalse();
});

test('listings rebuild logical shallow and deep trees with file metadata', function (): void {
    $inner = new class($this->blobRoot) extends LocalFilesystemAdapter
    {
        public function listContents(string $path, bool $deep): iterable
        {
            foreach (parent::listContents($path, $deep) as $item) {
                if ($item instanceof FileAttributes && $item->path() === 'media~cover.png') {
                    yield new FileAttributes(
                        $item->path(),
                        17,
                        Visibility::PUBLIC,
                        1_700_000_000,
                        'image/png',
                        ['etag' => 'synthetic'],
                    );

                    continue;
                }

                yield $item;
            }
        }
    };
    $adapter = new FlatBlobAdapter($inner);
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);

    $inner->write('media~cover.png', $png, $this->config);
    $inner->write('media~2026~january~photo.png', $png, $this->config);
    $inner->write('media~2026~february~clip.bin', 'clip', $this->config);
    $inner->write('library~note.txt', 'note', $this->config);

    $shallow = collect(iterator_to_array($adapter->listContents('media', false), false));

    expect($shallow->map(fn (FileAttributes|DirectoryAttributes $item): string => $item->path())->all())->toEqualCanonicalizing([
        'media/cover.png',
        'media/2026',
    ])->and($shallow->first(fn (FileAttributes|DirectoryAttributes $item): bool => $item->path() === 'media/2026'))->toBeInstanceOf(DirectoryAttributes::class);

    $cover = $shallow->first(fn (FileAttributes|DirectoryAttributes $item): bool => $item->path() === 'media/cover.png');

    expect($cover)->toBeInstanceOf(FileAttributes::class)
        ->and($cover->fileSize())->toBe(17)
        ->and($cover->visibility())->toBe(Visibility::PUBLIC)
        ->and($cover->lastModified())->toBe(1_700_000_000)
        ->and($cover->mimeType())->toBe('image/png')
        ->and($cover->extraMetadata())->toBe(['etag' => 'synthetic']);

    $deep = collect(iterator_to_array($adapter->listContents('media', true), false));

    expect($deep->map(fn (FileAttributes|DirectoryAttributes $item): string => $item->path())->all())->toEqualCanonicalizing([
        'media/cover.png',
        'media/2026',
        'media/2026/january',
        'media/2026/january/photo.png',
        'media/2026/february',
        'media/2026/february/clip.bin',
    ])->and($deep->contains(fn (FileAttributes|DirectoryAttributes $item): bool => $item->path() === 'library/note.txt'))->toBeFalse();
});

test('directory probes and deletion use an exact flat-name prefix', function (): void {
    $this->inner->write('media', 'root file', $this->config);
    $this->inner->write('media~photo.jpg', 'photo', $this->config);
    $this->inner->write('media~nested~one.jpg', 'one', $this->config);
    $this->inner->write('media2~sibling.jpg', 'sibling', $this->config);
    $this->inner->write('medi~near.jpg', 'near', $this->config);

    expect($this->adapter->directoryExists('media'))->toBeTrue()
        ->and($this->adapter->directoryExists('media/nested'))->toBeTrue()
        ->and($this->adapter->directoryExists('media/nested/missing'))->toBeFalse()
        ->and($this->adapter->directoryExists('missing'))->toBeFalse();

    $this->adapter->createDirectory('media/empty', $this->config);

    expect($this->adapter->directoryExists('media/empty'))->toBeFalse()
        ->and(is_dir($this->blobRoot.'/media~empty'))->toBeFalse();

    expect(fn () => $this->adapter->deleteDirectory(''))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->adapter->deleteDirectory('/'))->toThrow(InvalidArgumentException::class)
        ->and($this->inner->fileExists('media~photo.jpg'))->toBeTrue();

    $this->adapter->deleteDirectory('media');

    expect($this->adapter->fileExists('media'))->toBeTrue()
        ->and($this->inner->fileExists('media~photo.jpg'))->toBeFalse()
        ->and($this->inner->fileExists('media~nested~one.jpg'))->toBeFalse()
        ->and($this->inner->fileExists('media2~sibling.jpg'))->toBeTrue()
        ->and($this->inner->fileExists('medi~near.jpg'))->toBeTrue();
});

test('empty and traversal file operations are refused', function (): void {
    expect(fn () => $this->adapter->fileExists(''))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->adapter->read('../outside.txt'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->adapter->delete('media/./photo.jpg'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->adapter->createDirectory('../outside', $this->config))->toThrow(InvalidArgumentException::class);
});
