import json
import re
import tempfile
import unittest
from pathlib import Path

from ehlib.translate.tag_translator import TagTranslator
from ehlib.utils.helpers import extract_tag_match_keys_from_href


class DatabaseInsertShapeTests(unittest.TestCase):
    def test_gallery_insert_columns_match_placeholders(self) -> None:
        source = Path("ehlib/models/database.py").read_text(encoding="utf-8")
        match = re.search(
            r"INSERT INTO galleries\s*\((.*?)\)\s*VALUES\s*\((.*?)\)",
            source,
            re.DOTALL,
        )
        self.assertIsNotNone(match)
        columns = [column.strip() for column in match.group(1).split(",")]
        placeholders = re.findall(r"\?", match.group(2))
        self.assertEqual(len(columns), 21)
        self.assertEqual(len(placeholders), len(columns))


class TagHrefMatchKeyTests(unittest.TestCase):
    def test_decodes_percent_encoded_character_key(self) -> None:
        href = "/tag/character:kazuto%20kirigaya%24"
        self.assertEqual(extract_tag_match_keys_from_href(href), ["kazuto kirigaya"])

    def test_decodes_multiple_aliases_and_plus_spaces(self) -> None:
        href = "/tag/character:kirito%7Ckazuto+kirigaya%24"
        self.assertEqual(
            extract_tag_match_keys_from_href(href),
            ["kirito", "kazuto kirigaya"],
        )

    def test_supports_legacy_namespace_link(self) -> None:
        self.assertEqual(
            extract_tag_match_keys_from_href("/artist/example+artist%24"),
            ["example artist"],
        )


class TagTranslatorAliasTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temp_dir = tempfile.TemporaryDirectory()
        self.db_path = Path(self.temp_dir.name) / "tags.json"
        self.db_path.write_text(
            json.dumps(
                {
                    "data": [
                        {
                            "namespace": "character",
                            "data": {
                                "kazuto kirigaya | kirito": {"name": "\u6850\u8c37\u548c\u4eba"},
                            },
                        }
                    ]
                },
                ensure_ascii=False,
            ),
            encoding="utf-8",
        )
        self.translator = TagTranslator(self.db_path)
        self.assertTrue(self.translator.load())

    def tearDown(self) -> None:
        self.temp_dir.cleanup()

    def test_database_aliases_are_indexed_individually(self) -> None:
        expected = "\u6850\u8c37\u548c\u4eba"
        self.assertEqual(self.translator.translate("character", "kirito"), expected)
        self.assertEqual(self.translator.translate("character", "kazuto kirigaya"), expected)

    def test_href_match_keys_are_used_for_translation(self) -> None:
        raw = json.dumps(
            [
                {
                    "type": "character",
                    "name": "kirito",
                    "match_keys": ["kazuto kirigaya"],
                }
            ]
        )
        translated = json.loads(self.translator.translate_tags(raw))
        self.assertEqual(translated[0]["name_cn"], "\u6850\u8c37\u548c\u4eba")


if __name__ == "__main__":
    unittest.main()
