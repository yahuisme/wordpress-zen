#!/usr/bin/env python3
"""Merge the tracked Zen archive with its authorized, pinned Kai font overlay."""
import argparse
import copy
import hashlib
from pathlib import Path, PurePosixPath
import re
import tempfile
import zipfile

FONT_SOURCE_SHA256 = '6ef6d9b321ccad2a2d49d2d66c6e20b02e89e730d2323ca4b2e1154d08345a56'
PREFIX = 'zen/assets/zen-kai/'
USE_NOTICE = '''仓耳今楷网页字体说明

本目录包含仓耳今楷02-W04（TsangerJinKai02 W04）网页字体分包，版权属于北京仓耳文字技术有限公司，不属于 Zen 主题的 GPL 许可证。

维护者已确认获得将这些字体随 Zen Release 安装包公开再分发的授权。本次沿用既有字体分包及 CSS，不修改字形、字符范围或字宽。该再分发授权不将字体变成开源字体，也不自动授予使用者其他商业使用或再次分发的权利；具体使用范围请另行核对权利方许可。

请同时保留本文件及 NOTICE.md。权利方信息：https://www.tsanger.cn/product/33
'''


def read_archive(path):
    with zipfile.ZipFile(path) as archive:
        entries = archive.infolist()
        if len(entries) != len({item.filename for item in entries}):
            raise ValueError('ZIP contains duplicate paths')
        payload = {}
        for item in entries:
            parts = PurePosixPath(item.filename).parts
            if not parts or parts[0] != 'zen' or '..' in parts or '\\' in item.filename:
                raise ValueError('Unsafe ZIP path: ' + item.filename)
            if not item.is_dir():
                payload[item.filename] = (copy.copy(item), archive.read(item))
        return payload


def package(tracked, font_source, output):
    tracked, font_source, output = map(Path, (tracked, font_source, output))
    if output.resolve() in (tracked.resolve(), font_source.resolve()):
        raise ValueError('Output must not overwrite an input')
    digest = hashlib.sha256(font_source.read_bytes()).hexdigest()
    if digest != FONT_SOURCE_SHA256:
        raise ValueError('Font source SHA-256 mismatch')
    payload = read_archive(tracked)
    if 'zen/style.css' not in payload or any(name.startswith(PREFIX) for name in payload):
        raise ValueError('Expected a tracked-only Zen theme archive')
    version = re.findall(rb'^Version:\s*(\S+)', payload['zen/style.css'][1], re.M)
    if len(version) != 1:
        raise ValueError('Theme version missing or duplicated')
    source = read_archive(font_source)
    overlay = {name: item for name, item in source.items() if name.startswith(PREFIX)}
    overlay.pop(PREFIX + 'PERSONAL-USE.txt', None)
    css_path = PREFIX + 'assets/css/zen-bins-kai.css'
    if css_path not in overlay or PREFIX + 'NOTICE.md' not in overlay:
        raise ValueError('Font CSS or copyright notice missing')
    css = overlay[css_path][1].decode('utf-8')
    import posixpath
    references = {posixpath.normpath(posixpath.join(posixpath.dirname(css_path), url)) for url in re.findall(r'url\("([^"\)]+)"\)', css)}
    fonts = {name for name in overlay if name.endswith('.woff2')}
    if not fonts or references != fonts or set(overlay) != fonts | {css_path, PREFIX + 'NOTICE.md'}:
        raise ValueError('Font overlay member set or CSS references differ')
    if any(not overlay[name][1].startswith(b'wOF2') for name in fonts):
        raise ValueError('Invalid WOFF2 font signature')
    notice_info = zipfile.ZipInfo(PREFIX + 'RELEASE-USE.txt', payload['zen/style.css'][0].date_time)
    notice_info.compress_type = zipfile.ZIP_DEFLATED
    notice_info.external_attr = 0o100644 << 16
    overlay[notice_info.filename] = (notice_info, USE_NOTICE.encode('utf-8'))
    payload.update(overlay)
    output.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.NamedTemporaryFile(prefix='.zen-package-', suffix='.zip', dir=output.parent, delete=False) as handle:
        temporary = Path(handle.name)
    try:
        with zipfile.ZipFile(temporary, 'w') as archive:
            for name in sorted(payload):
                info, data = payload[name]
                archive.writestr(copy.copy(info), data)
        actual = read_archive(temporary)
        if set(actual) != set(payload) or any(actual[name][1] != payload[name][1] for name in payload):
            raise ValueError('Final package differs from validated inputs')
        temporary.replace(output)
    finally:
        temporary.unlink(missing_ok=True)
    return {'version': version[0].decode('ascii'), 'files': len(payload), 'font_files': len(fonts), 'sha256': hashlib.sha256(output.read_bytes()).hexdigest()}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('tracked', type=Path, help='Validated Git archive of theme/zen')
    parser.add_argument('font_source', type=Path, help='Pinned v2.0.0 installer containing the authorized font overlay')
    parser.add_argument('output', type=Path, help='Complete installable zen.zip')
    args = parser.parse_args()
    import json
    try:
        print(json.dumps(package(args.tracked, args.font_source, args.output), ensure_ascii=False))
    except (OSError, ValueError, zipfile.BadZipFile) as error:
        parser.exit(1, str(error) + '\n')


if __name__ == '__main__':
    main()
