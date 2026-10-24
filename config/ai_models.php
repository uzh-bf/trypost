<?php

declare(strict_types=1);

return [
    'default' => env('AI_MODEL_DEFAULT'),
    'max_tokens' => (int) env('AI_MODEL_MAX_TOKENS', 4096),
    'timeout' => (int) env('AI_MODEL_TIMEOUT', 50),
    'fitting_timeout' => (int) env('AI_MODEL_FITTING_TIMEOUT', 40),
    'profiles' => [
        'sol' => [
            'label' => 'GPT-6.1 Sol',
            'destination' => 'azure',
            'provider' => 'litellm',
            'model' => env('AI_SOL_MODEL'),
            'enabled' => (bool) env('AI_SOL_ENABLED', true),
            'temperature' => false,
            'capabilities' => ['text', 'structured', 'vision'],
            'options' => [
                'reasoning' => ['effort' => 'low'],
                'num_retries' => 0,
                'disable_fallbacks' => true,
            ],
        ],
        'opus' => [
            'label' => 'Claude Opus 5.5',
            'destination' => 'openrouter',
            'provider' => 'openrouter',
            'model' => env('AI_OPUS_MODEL'),
            'enabled' => (bool) env('AI_OPUS_ENABLED', true),
            'temperature' => false,
            'capabilities' => ['text', 'structured', 'vision'],
            'options' => [
                'reasoning' => ['effort' => 'low'],
                'provider' => [
                    'data_collection' => 'deny',
                    'allow_fallbacks' => false,
                    'require_parameters' => true,
                ],
            ],
        ],
        'gemini' => [
            'label' => 'Gemini 4 Argon',
            'destination' => 'openrouter',
            'provider' => 'openrouter',
            'model' => env('AI_GEMINI_MODEL'),
            'enabled' => (bool) env('AI_GEMINI_ENABLED', true),
            'temperature' => true,
            'capabilities' => ['text', 'structured', 'vision'],
            'options' => [
                'reasoning' => ['effort' => 'low'],
                'provider' => [
                    'data_collection' => 'deny',
                    'allow_fallbacks' => false,
                    'require_parameters' => true,
                ],
            ],
        ],
    ],
];
