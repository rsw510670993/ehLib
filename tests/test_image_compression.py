import tempfile
import unittest
from io import BytesIO
from pathlib import Path

from ehlib.storage.file_manager import FileManager
from ehlib.utils.image_compression import ImageCompressor


class ImageCompressionTests(unittest.TestCase):
    def test_conservative_defaults_and_invalid_value_bounds(self):
        compressor = ImageCompressor()
        self.assertTrue(compressor.enabled)
        self.assertEqual(compressor.quality, 88)
        self.assertEqual(compressor.method, 4)
        self.assertEqual(compressor.min_savings_percent, 5.0)

        bounded = ImageCompressor(quality="bad", method=99, min_savings_percent=-1)
        self.assertEqual(bounded.quality, 88)
        self.assertEqual(bounded.method, 6)
        self.assertEqual(bounded.min_savings_percent, 0.0)

    def test_disabled_compression_preserves_original_bytes(self):
        compressor = ImageCompressor(enabled=False)
        with tempfile.TemporaryDirectory() as temp_dir:
            path = Path(temp_dir) / "001.jpg"
            data = b"original-image-bytes"
            saved_path = compressor.save_page_bytes(data, path)
            self.assertEqual(saved_path, path)
            self.assertEqual(path.read_bytes(), data)
            self.assertFalse(path.with_name(path.name + ".part").exists())

    def test_invalid_image_falls_back_to_original_format(self):
        compressor = ImageCompressor(enabled=True)
        with tempfile.TemporaryDirectory() as temp_dir:
            path = Path(temp_dir) / "001.jpg"
            data = b"not-a-decodable-image"
            saved_path = compressor.save_page_bytes(data, path)
            self.assertEqual(saved_path, path)
            self.assertEqual(path.read_bytes(), data)
            self.assertFalse(path.with_suffix(".webp").exists())

    def test_existing_webp_satisfies_original_page_request(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            requested_path = Path(temp_dir) / "001.jpg"
            webp_path = requested_path.with_suffix(".webp")
            webp_path.write_bytes(b"existing-webp")
            self.assertEqual(ImageCompressor.find_existing_page_file(requested_path), webp_path)

    def test_first_page_prefers_webp_without_deleting_original(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            gallery_dir = Path(temp_dir) / "gallery"
            gallery_dir.mkdir()
            jpg_path = gallery_dir / "001.jpg"
            webp_path = gallery_dir / "001.webp"
            jpg_path.write_bytes(b"original-jpg")
            webp_path.write_bytes(b"compressed-webp")
            manager = FileManager(temp_dir)
            self.assertEqual(manager.first_page_path(gallery_dir), webp_path)
            self.assertTrue(jpg_path.exists())
    def test_lossy_webp_keeps_dimensions_when_encoder_is_available(self):
        try:
            from PIL import Image, features
        except ImportError:
            self.skipTest("Pillow is not installed in this development environment")
        if not features.check("webp"):
            self.skipTest("Pillow WebP encoder is unavailable")

        image = Image.new("RGB", (640, 960), "white")
        source = BytesIO()
        image.save(source, format="BMP")
        compressor = ImageCompressor(quality=88, method=4, min_savings_percent=5)
        with tempfile.TemporaryDirectory() as temp_dir:
            requested_path = Path(temp_dir) / "001.png"
            requested_path.write_bytes(b"existing-original")
            saved_path = compressor.save_page_bytes(source.getvalue(), requested_path)
            self.assertEqual(saved_path.suffix, ".webp")
            self.assertLess(saved_path.stat().st_size, len(source.getvalue()) * 0.95)
            self.assertEqual(requested_path.read_bytes(), b"existing-original")
            with Image.open(saved_path) as result:
                self.assertEqual(result.size, (640, 960))
                self.assertEqual(result.format, "WEBP")


if __name__ == "__main__":
    unittest.main()