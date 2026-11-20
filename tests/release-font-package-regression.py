"""Exercise the real font-inclusive packager with synthetic archives only."""
import hashlib
import importlib.util
from pathlib import Path
import tempfile
import unittest
import warnings
import zipfile

spec = importlib.util.spec_from_file_location('zen_release_package', Path(__file__).resolve().parents[1] / 'scripts/package-release.py')
assert spec is not None and spec.loader is not None
packager = importlib.util.module_from_spec(spec)
spec.loader.exec_module(packager)


class FontPackageTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='zen-font-package-')
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.tracked, self.source, self.output = [self.root / name for name in ['tracked.zip','font-source.zip','zen.zip']]
        self.prefix = packager.PREFIX
        self.write(self.tracked, {'zen/style.css':b'Theme Name: Zen\nVersion: 2.0.1\n','zen/index.php':b'<?php // tracked fixture'})
        self.font = self.prefix + 'assets/fonts/base.woff2'
        self.css = self.prefix + 'assets/css/zen-bins-kai.css'
        self.overlay = {self.css:b'@font-face{font-family:"Zen Kami Kai";src:url("../fonts/base.woff2")}',self.font:b'wOF2fixture',self.prefix+'NOTICE.md':b'Original font copyright',self.prefix+'PERSONAL-USE.txt':b'Old packaging-use note'}
        self.write(self.source, {'zen/style.css':b'Version: 2.0.0\n',**self.overlay})
        self.old_digest = packager.FONT_SOURCE_SHA256
        setattr(packager, 'FONT_SOURCE_SHA256', hashlib.sha256(self.source.read_bytes()).hexdigest())
        self.addCleanup(setattr,packager,'FONT_SOURCE_SHA256',self.old_digest)
        self.output.write_bytes(b'previous installer must survive rejected input')

    def write(self,path,files):
        with zipfile.ZipFile(path,'w') as archive:
            for name,data in files.items():archive.writestr(name,data)

    def rejected(self,match):
        before=self.output.read_bytes()
        with self.assertRaisesRegex(ValueError,match):packager.package(self.tracked,self.source,self.output)
        self.assertEqual(self.output.read_bytes(),before)

    def test_merge_preserves_tracked_and_font_bytes(self):
        result=packager.package(self.tracked,self.source,self.output)
        self.assertEqual(result['version'],'2.0.1')
        with zipfile.ZipFile(self.output) as archive:
            self.assertEqual(len(archive.namelist()),6)
            self.assertIsNone(archive.testzip())
            self.assertEqual(archive.read('zen/index.php'),b'<?php // tracked fixture')
            self.assertEqual(archive.read(self.font),self.overlay[self.font])
            self.assertEqual(archive.read(self.prefix+'NOTICE.md'),self.overlay[self.prefix+'NOTICE.md'])
            self.assertNotIn(self.prefix+'PERSONAL-USE.txt',archive.namelist())
            self.assertEqual(archive.read(self.prefix+'RELEASE-USE.txt').decode(),packager.USE_NOTICE)

    def test_unused_code_alias_is_removed_without_changing_editorial_faces(self):
        editorial=self.overlay[self.css]
        alias=b'@font-face { font-family: "TsangerJinKai02 Code CJK"; src:url("../fonts/base.woff2"); unicode-range:U+4E00; }'
        variables=b':root { --zen-bins-editorial: "Zen Kami Kai"; --zen-bins-code: "unused"; }'
        self.overlay[self.css]=editorial+b'\n'+alias+b'\n'+variables
        self.write(self.source,{'zen/style.css':b'Version: 2.0.0\n',**self.overlay})
        setattr(packager,'FONT_SOURCE_SHA256',hashlib.sha256(self.source.read_bytes()).hexdigest())
        packager.package(self.tracked,self.source,self.output)
        with zipfile.ZipFile(self.output) as archive:
            css=archive.read(self.css)
            self.assertIn(editorial,css)
            self.assertNotIn(b'TsangerJinKai02 Code CJK',css)
            self.assertNotIn(b'--zen-bins-code',css)
            self.assertNotIn(b'--zen-bins-editorial',css)
            self.assertEqual(archive.read(self.font),self.overlay[self.font])

    def test_changed_source_is_refused_without_replacing_output(self):
        self.source.write_bytes(self.source.read_bytes()+b'changed')
        self.rejected('SHA-256 mismatch')

    def test_duplicate_tracked_path_is_refused(self):
        with warnings.catch_warnings():
            warnings.simplefilter('ignore',UserWarning)
            with zipfile.ZipFile(self.tracked,'a') as archive:archive.writestr('zen/style.css',b'Version: 2.0.1')
        self.rejected('duplicate')

    def test_traversal_is_refused(self):
        with zipfile.ZipFile(self.tracked,'a') as archive:archive.writestr('zen/../outside.php',b'not allowed')
        self.rejected('Unsafe ZIP path')

    def test_prebundled_fonts_are_refused(self):
        with zipfile.ZipFile(self.tracked,'a') as archive:archive.writestr(self.font,b'wOF2unexpected')
        self.rejected('tracked-only')

    def test_missing_version_is_refused(self):
        self.write(self.tracked,{'zen/style.css':b'Theme Name: Zen\n'})
        self.rejected('version missing')

    def test_corrupted_tracked_member_is_refused(self):
        data=bytearray(self.tracked.read_bytes())
        with zipfile.ZipFile(self.tracked) as archive:
            entry=archive.getinfo('zen/index.php')
        offset=entry.header_offset+30+len(entry.filename.encode('utf-8'))+len(entry.extra)
        data[offset]^=1
        self.tracked.write_bytes(data)
        before=self.output.read_bytes()
        with self.assertRaises(zipfile.BadZipFile):packager.package(self.tracked,self.source,self.output)
        self.assertEqual(self.output.read_bytes(),before)

    def test_output_cannot_overwrite_an_input(self):
        before=self.tracked.read_bytes()
        with self.assertRaisesRegex(ValueError,'overwrite an input'):packager.package(self.tracked,self.source,self.tracked)
        self.assertEqual(self.tracked.read_bytes(),before)


if __name__=='__main__':unittest.main()
