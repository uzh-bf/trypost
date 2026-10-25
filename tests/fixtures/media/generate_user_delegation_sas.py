from __future__ import annotations

import base64
import hashlib
import json
from datetime import datetime, timezone
from importlib.metadata import version
from pathlib import Path
from urllib.parse import quote

from azure.storage.blob import BlobSasPermissions, UserDelegationKey
from azure.storage.blob import _shared_access_signature as sas_module
from azure.storage.blob._shared_access_signature import BlobSharedAccessSignature


ACCOUNT = "trypostsynthetic"
CONTAINER = "media"
SAS_VERSION = "2023-11-03"
KEY_VALUE = base64.b64encode(b"0123456789abcdef0123456789abcdef").decode()

KEY = UserDelegationKey()
KEY.signed_oid = "11111111-1111-4111-8111-111111111111"
KEY.signed_tid = "22222222-2222-4222-8222-222222222222"
KEY.signed_start = "2026-10-25T09:55:00Z"
KEY.signed_expiry = "2026-10-27T10:00:00Z"
KEY.signed_service = "b"
KEY.signed_version = SAS_VERSION
KEY.value = KEY_VALUE

START = datetime(2026, 10, 25, 9, 55, tzinfo=timezone.utc)
EXPIRY = datetime(2026, 10, 26, 12, 0, tzinfo=timezone.utc)
BLOB_NAMES = [
    "avatar-01~v1.webp",
    "photo #1 + cafe%?.jpg",
    "unicode-caf\u00e9.png",
]

vectors = []
capture_enabled = False
captured: list[str] = []
sign_string = sas_module.sign_string


def capture_string_to_sign(key: str, statement: str) -> str:
    if capture_enabled:
        captured.append(statement)

    return sign_string(key, statement)


sas_module.sign_string = capture_string_to_sign

for blob_name in BLOB_NAMES:
    signer = BlobSharedAccessSignature(ACCOUNT, user_delegation_key=KEY)
    signer.x_ms_version = SAS_VERSION
    captured.clear()
    capture_enabled = True

    query = signer.generate_blob(
        CONTAINER,
        blob_name,
        permission=BlobSasPermissions(read=True),
        expiry=EXPIRY,
        start=START,
        protocol="https",
    )
    capture_enabled = False
    encoded_blob_name = quote(blob_name, safe="~")
    vectors.append(
        {
            "blob_name": blob_name,
            "encoded_blob_name": encoded_blob_name,
            "url": f"https://{ACCOUNT}.blob.core.windows.net/{CONTAINER}/{encoded_blob_name}?{query}",
            "sas_query": query,
            "string_to_sign": captured[-1],
        }
    )

fixture = {
    "generator": {
        "package": "azure-storage-blob",
        "version": version("azure-storage-blob"),
        "command": "uv run --with azure-storage-blob==12.19.0 python tests/fixtures/media/generate_user_delegation_sas.py",
        "script_sha256": hashlib.sha256(Path(__file__).read_bytes()).hexdigest(),
        "notes": (
            "Generated offline with the official Python SDK, a synthetic fixed delegation key, "
            "fixed timestamps, HTTPS-only read permission, and the SDK's 2023-11-03 SAS version. "
            "No Azure credentials or services were used."
        ),
    },
    "fixed": {
        "account_name": ACCOUNT,
        "container_name": CONTAINER,
        "sas_version": SAS_VERSION,
        "start": "2026-10-25T09:55:00Z",
        "expiry": "2026-10-26T12:00:00Z",
        "delegation_key": {
            "signed_oid": KEY.signed_oid,
            "signed_tid": KEY.signed_tid,
            "signed_start": KEY.signed_start,
            "signed_expiry": KEY.signed_expiry,
            "signed_service": KEY.signed_service,
            "signed_version": KEY.signed_version,
            "value": KEY.value,
        },
    },
    "vectors": vectors,
}

print(json.dumps(fixture, indent=2, ensure_ascii=False))
