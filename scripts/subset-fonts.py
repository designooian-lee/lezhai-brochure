from pathlib import Path

from fontTools import subset
from fontTools.ttLib import TTFont

ROOT = Path(__file__).resolve().parents[1]
FONT_DIR = ROOT / "website" / "public" / "fonts"
SOURCE_DIR = ROOT / "assets" / "font-sources"
CSS_FILE = ROOT / "website" / "src" / "styles" / "global.css"


def public_characters() -> set[int]:
    characters = set(range(0x20, 0x7F))
    sources = [ROOT / "src" / "WebsiteApp.php"]
    sources.extend(
        path
        for path in (ROOT / "website" / "src").rglob("*")
        if path.suffix in {".astro", ".ts", ".tsx"}
    )
    for source in sources:
        characters.update(ord(character) for character in source.read_text(encoding="utf-8"))
    return characters


def save_subset(source: Path, target: Path, unicodes: set[int]) -> None:
    options = subset.Options()
    options.flavor = "woff2"
    options.layout_features = ["*"]
    options.name_IDs = ["*"]
    options.name_legacy = True
    options.name_languages = ["*"]
    font = TTFont(source)
    subsetter = subset.Subsetter(options=options)
    subsetter.populate(unicodes=unicodes)
    subsetter.subset(font)
    font.save(target)


def unicode_ranges(unicodes: set[int]) -> str:
    points = sorted(unicodes)
    ranges: list[tuple[int, int]] = []
    start = previous = points[0]
    for point in points[1:]:
        if point == previous + 1:
            previous = point
            continue
        ranges.append((start, previous))
        start = previous = point
    ranges.append((start, previous))
    return ",".join(
        f"U+{start:X}" if start == end else f"U+{start:X}-{end:X}"
        for start, end in ranges
    )


noto_characters = public_characters()
inter_characters = set(range(0x20, 0x100)) | set(range(0x2000, 0x2070))
save_subset(
    SOURCE_DIR / "NotoSansSC-Variable.ttf",
    FONT_DIR / "NotoSansSC-Site.woff2",
    noto_characters,
)
save_subset(
    SOURCE_DIR / "Inter-Variable.ttf",
    FONT_DIR / "Inter-Latin.woff2",
    inter_characters,
)
noto_range = unicode_ranges(noto_characters)
inter_range = unicode_ranges(inter_characters)
css_lines = CSS_FILE.read_text(encoding="utf-8").splitlines()
css_lines[:2] = [
    "@font-face{font-family:'Noto Sans SC';src:url('/fonts/NotoSansSC-Site.woff2') format('woff2');font-weight:100 900;font-display:swap;unicode-range:" + noto_range + "}",
    "@font-face{font-family:'Inter';src:url('/fonts/Inter-Latin.woff2') format('woff2');font-weight:100 900;font-display:swap;unicode-range:" + inter_range + "}",
]
CSS_FILE.write_text("\n".join(css_lines) + "\n", encoding="utf-8")
print(noto_range)
