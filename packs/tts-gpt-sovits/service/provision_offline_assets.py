#!/usr/bin/env python3
"""Provision the pinned G2PW assets before an offline GPT-SoVITS run."""

from __future__ import annotations

import argparse
import hashlib
import os
import shutil
import tempfile
import urllib.request
import zipfile
from pathlib import Path


G2PW_ARCHIVE_URL = "https://www.modelscope.cn/models/kamiorinn/g2pw/resolve/master/G2PWModel_1.1.zip"
G2PW_ARCHIVE_SHA256 = "b116f6930a7ee55eef6576a8d8e14bf40c1106583439e8ae924b901512379c64"
G2PW_PREFIX = "G2PWModel_1.1/"
G2PW_FILES = (
    "char_bopomofo_dict.json",
    "POLYPHONIC_CHARS.txt",
    "MONOPHONIC_CHARS.txt",
    "record.log",
    "config.py",
    "g2pW.onnx",
    "bopomofo_to_pinyin_wo_tune_dict.json",
    "version",
)
NLTK_ARCHIVES = (
    (
        "cmudict",
        "https://raw.githubusercontent.com/nltk/nltk_data/gh-pages/packages/corpora/cmudict.zip",
        "d07cca47fd72ad32ea9d8ad1219f85301eeaf4568f8b6b73747506a71fb5afd6",
        "cmudict/",
        ("cmudict", "README"),
        "corpora/cmudict",
    ),
    (
        "averaged_perceptron_tagger",
        "https://raw.githubusercontent.com/nltk/nltk_data/gh-pages/packages/taggers/averaged_perceptron_tagger.zip",
        "e1f13cf2532daadfd6f3bc481a49859f0b8ea6432ccdcd83e6a49a5f19008de9",
        "averaged_perceptron_tagger/",
        ("averaged_perceptron_tagger.pickle",),
        "taggers/averaged_perceptron_tagger",
    ),
    (
        "averaged_perceptron_tagger_eng",
        "https://raw.githubusercontent.com/nltk/nltk_data/gh-pages/packages/taggers/averaged_perceptron_tagger_eng.zip",
        "6025f530624335c67d6547d44757b357b4e79bae030a0383e9887a92c1718f0b",
        "averaged_perceptron_tagger_eng/",
        (
            "averaged_perceptron_tagger_eng.weights.json",
            "averaged_perceptron_tagger_eng.classes.json",
            "averaged_perceptron_tagger_eng.tagdict.json",
        ),
        "taggers/averaged_perceptron_tagger_eng",
    ),
)


def regular(path: Path) -> bool:
    return path.is_file() and not path.is_symlink()


def model_root() -> Path:
    value = os.environ.get("GPT_SOVITS_MODEL_DIR", "")
    path = Path(value)
    if not value or not path.is_absolute() or "\x00" in value:
        raise RuntimeError("model_root_invalid")
    path.mkdir(parents=True, exist_ok=True)
    if path.is_symlink() or not path.is_dir():
        raise RuntimeError("model_root_invalid")
    return path


def g2pw_ready(destination: Path) -> bool:
    return destination.is_dir() and not destination.is_symlink() and all(regular(destination / name) for name in G2PW_FILES)


def nltk_ready(destination: Path) -> bool:
    return (
        destination.is_dir()
        and not destination.is_symlink()
        and regular(destination / "corpora/cmudict/cmudict")
        and regular(destination / "corpora/cmudict.zip")
        and regular(destination / "taggers/averaged_perceptron_tagger/averaged_perceptron_tagger.pickle")
        and regular(destination / "taggers/averaged_perceptron_tagger.zip")
        and regular(destination / "taggers/averaged_perceptron_tagger_eng/averaged_perceptron_tagger_eng.weights.json")
        and regular(destination / "taggers/averaged_perceptron_tagger_eng.zip")
    )


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def download_archive(url: str, destination: Path) -> None:
    request = urllib.request.Request(url, headers={"User-Agent": "3waAIHub-GPT-SoVITS/0.1"})
    with urllib.request.urlopen(request, timeout=300) as response, destination.open("wb") as stream:
        shutil.copyfileobj(response, stream)


def verify_archive(path: Path, expected_sha256: str, minimum_bytes: int, maximum_bytes: int, code: str) -> None:
    if not regular(path) or path.stat().st_size < minimum_bytes or path.stat().st_size > maximum_bytes:
        raise RuntimeError(code + "_invalid")
    if sha256(path) != expected_sha256:
        raise RuntimeError(code + "_checksum_invalid")


def extract_archive(archive_path: Path, destination: Path, prefix: str, files: tuple[str, ...], code: str) -> None:
    expected = {prefix + name for name in files}
    with zipfile.ZipFile(archive_path) as archive:
        names = {item.filename for item in archive.infolist() if not item.is_dir()}
        if names != expected:
            raise RuntimeError(code + "_layout_invalid")
        for name in expected:
            info = archive.getinfo(name)
            if (info.external_attr >> 16) & 0o170000 == 0o120000:
                raise RuntimeError(code + "_layout_invalid")
            target = destination / name.removeprefix(prefix)
            with archive.open(info) as source, target.open("wb") as output:
                shutil.copyfileobj(source, output)


def provision_g2pw(root: Path, temporary: Path, archive: str) -> None:
    destination = root / "g2pw"
    if g2pw_ready(destination):
        return
    if destination.exists():
        raise RuntimeError("g2pw_assets_invalid")
    archive_path = Path(archive) if archive else temporary / "G2PWModel_1.1.zip"
    if archive:
        if not archive_path.is_absolute() or archive_path.is_symlink():
            raise RuntimeError("g2pw_archive_invalid")
    else:
        download_archive(G2PW_ARCHIVE_URL, archive_path)
    verify_archive(archive_path, G2PW_ARCHIVE_SHA256, 100 * 1024 * 1024, 700 * 1024 * 1024, "g2pw_archive")
    stage = temporary / "g2pw"
    stage.mkdir()
    extract_archive(archive_path, stage, G2PW_PREFIX, G2PW_FILES, "g2pw_archive")
    if not g2pw_ready(stage):
        raise RuntimeError("g2pw_assets_invalid")
    stage.replace(destination)


def provision_nltk(root: Path, temporary: Path) -> None:
    destination = root / "nltk_data"
    if nltk_ready(destination):
        return
    if destination.exists():
        raise RuntimeError("nltk_assets_invalid")
    stage = temporary / "nltk_data"
    for name, url, digest, prefix, files, relative_dir in NLTK_ARCHIVES:
        archive_path = temporary / (name + ".zip")
        download_archive(url, archive_path)
        verify_archive(archive_path, digest, 1024, 10 * 1024 * 1024, "nltk_archive")
        target = stage / relative_dir
        target.mkdir(parents=True, exist_ok=True)
        extract_archive(archive_path, target, prefix, files, "nltk_archive")
        shutil.copyfile(archive_path, stage / Path(relative_dir).parent / (name + ".zip"))
    if not nltk_ready(stage):
        raise RuntimeError("nltk_assets_invalid")
    stage.replace(destination)


def main() -> int:
    parser = argparse.ArgumentParser(description="Provision pinned offline GPT-SoVITS G2PW assets")
    parser.add_argument("--archive", default="", help="Verified local archive path for an air-gapped install")
    args = parser.parse_args()

    root = model_root()
    temporary = Path(tempfile.mkdtemp(prefix=".g2pw-provision-", dir=root))
    try:
        provision_g2pw(root, temporary, args.archive)
        provision_nltk(root, temporary)
    finally:
        shutil.rmtree(temporary, ignore_errors=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
