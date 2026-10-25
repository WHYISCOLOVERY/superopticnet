from __future__ import annotations

import html
from pathlib import Path
from typing import Iterable

from reportlab.lib import colors
from reportlab.lib.enums import TA_CENTER
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import mm
from reportlab.platypus import (
    BaseDocTemplate,
    Frame,
    KeepTogether,
    PageTemplate,
    Paragraph,
    Preformatted,
    Spacer,
    Table,
    TableStyle,
)


ROOT = Path(__file__).resolve().parents[1]
DOCS = ROOT / "docs" / "vendor" / "payment-providers"
OUTPUT = ROOT / "output" / "pdf"


def page_footer(canvas, document) -> None:
    canvas.saveState()
    canvas.setFont("Helvetica", 8)
    canvas.setFillColor(colors.HexColor("#607089"))
    canvas.drawString(18 * mm, 10 * mm, "SuperOptic - Confidential technical integration document")
    canvas.drawRightString(192 * mm, 10 * mm, f"Page {document.page}")
    canvas.restoreState()


def styles():
    base = getSampleStyleSheet()
    return {
        "title": ParagraphStyle(
            "Title",
            parent=base["Title"],
            fontName="Helvetica-Bold",
            fontSize=22,
            leading=27,
            textColor=colors.HexColor("#102A43"),
            alignment=TA_CENTER,
            spaceAfter=12,
        ),
        "h1": ParagraphStyle(
            "H1",
            parent=base["Heading1"],
            fontName="Helvetica-Bold",
            fontSize=16,
            leading=20,
            textColor=colors.HexColor("#102A43"),
            spaceBefore=10,
            spaceAfter=7,
        ),
        "h2": ParagraphStyle(
            "H2",
            parent=base["Heading2"],
            fontName="Helvetica-Bold",
            fontSize=12,
            leading=15,
            textColor=colors.HexColor("#007C91"),
            spaceBefore=8,
            spaceAfter=5,
        ),
        "body": ParagraphStyle(
            "Body",
            parent=base["BodyText"],
            fontName="Helvetica",
            fontSize=9.5,
            leading=14,
            textColor=colors.HexColor("#243B53"),
            spaceAfter=6,
        ),
        "bullet": ParagraphStyle(
            "Bullet",
            parent=base["BodyText"],
            fontName="Helvetica",
            fontSize=9.5,
            leading=14,
            leftIndent=12,
            firstLineIndent=-7,
            bulletIndent=5,
            textColor=colors.HexColor("#243B53"),
            spaceAfter=3,
        ),
        "code": ParagraphStyle(
            "Code",
            fontName="Courier",
            fontSize=7.4,
            leading=10,
            textColor=colors.HexColor("#102A43"),
            backColor=colors.HexColor("#F0F4F8"),
            borderColor=colors.HexColor("#D9E2EC"),
            borderWidth=0.5,
            borderPadding=7,
            spaceBefore=3,
            spaceAfter=8,
        ),
        "notice": ParagraphStyle(
            "Notice",
            parent=base["BodyText"],
            fontName="Helvetica-Bold",
            fontSize=9.5,
            leading=14,
            textColor=colors.HexColor("#7C2D12"),
            backColor=colors.HexColor("#FFF7ED"),
            borderColor=colors.HexColor("#FDBA74"),
            borderWidth=0.8,
            borderPadding=8,
            spaceAfter=10,
        ),
    }


def paragraph_text(lines: list[str]) -> str:
    text = " ".join(part.strip() for part in lines)
    return html.escape(text.replace("**", "").replace("`", ""))


def markdown_story(paths: Iterable[Path]):
    style = styles()
    story = []
    for file_index, path in enumerate(paths):
        lines = path.read_text(encoding="utf-8").splitlines()
        paragraph: list[str] = []
        code: list[str] = []
        in_code = False
        table_rows: list[list[str]] = []

        def flush_paragraph() -> None:
            if paragraph:
                text = paragraph_text(paragraph)
                target = style["notice"] if text.startswith("Status:") else style["body"]
                story.append(Paragraph(text, target))
                paragraph.clear()

        def flush_code() -> None:
            if code:
                story.append(Preformatted("\n".join(code), style["code"]))
                code.clear()

        def flush_table() -> None:
            if not table_rows:
                return
            filtered = [
                row
                for row in table_rows
                if not all(cell.replace("-", "").replace(":", "").strip() == "" for cell in row)
            ]
            if filtered:
                table = Table(filtered, repeatRows=1, hAlign="LEFT")
                table.setStyle(
                    TableStyle(
                        [
                            ("BACKGROUND", (0, 0), (-1, 0), colors.HexColor("#D9EEF2")),
                            ("TEXTCOLOR", (0, 0), (-1, 0), colors.HexColor("#102A43")),
                            ("FONTNAME", (0, 0), (-1, 0), "Helvetica-Bold"),
                            ("FONTNAME", (0, 1), (-1, -1), "Helvetica"),
                            ("FONTSIZE", (0, 0), (-1, -1), 7.5),
                            ("LEADING", (0, 0), (-1, -1), 10),
                            ("GRID", (0, 0), (-1, -1), 0.4, colors.HexColor("#BCCCDC")),
                            ("VALIGN", (0, 0), (-1, -1), "TOP"),
                            ("LEFTPADDING", (0, 0), (-1, -1), 5),
                            ("RIGHTPADDING", (0, 0), (-1, -1), 5),
                            ("TOPPADDING", (0, 0), (-1, -1), 4),
                            ("BOTTOMPADDING", (0, 0), (-1, -1), 4),
                        ]
                    )
                )
                story.append(table)
                story.append(Spacer(1, 7))
            table_rows.clear()

        if file_index:
            story.append(Spacer(1, 12))

        for line in lines:
            if line.startswith("```"):
                flush_paragraph()
                flush_table()
                if in_code:
                    flush_code()
                    in_code = False
                else:
                    in_code = True
                continue
            if in_code:
                code.append(line)
                continue
            if line.startswith("|") and line.endswith("|"):
                flush_paragraph()
                table_rows.append([cell.strip().replace("`", "") for cell in line.strip("|").split("|")])
                continue
            flush_table()
            if not line.strip():
                flush_paragraph()
                continue
            if line.startswith("# "):
                flush_paragraph()
                title = line[2:].strip()
                story.append(Paragraph(title, style["title"] if not story else style["h1"]))
                continue
            if line.startswith("## "):
                flush_paragraph()
                story.append(Paragraph(line[3:].strip(), style["h1"]))
                continue
            if line.startswith("### "):
                flush_paragraph()
                story.append(Paragraph(line[4:].strip(), style["h2"]))
                continue
            if line.startswith("- [ ] "):
                flush_paragraph()
                story.append(Paragraph("[ ] " + paragraph_text([line[6:].strip()]), style["bullet"]))
                continue
            if line.startswith("- "):
                flush_paragraph()
                story.append(Paragraph("- " + paragraph_text([line[2:].strip()]), style["bullet"]))
                continue
            paragraph.append(line)
        flush_paragraph()
        flush_code()
        flush_table()
    return story


def build(output_name: str, provider_doc: str) -> Path:
    OUTPUT.mkdir(parents=True, exist_ok=True)
    output = OUTPUT / output_name
    document = BaseDocTemplate(
        str(output),
        pagesize=A4,
        leftMargin=18 * mm,
        rightMargin=18 * mm,
        topMargin=17 * mm,
        bottomMargin=23 * mm,
        title=output_name.removesuffix(".pdf"),
        author="SuperOptic",
        subject="Payment provider API integration",
    )
    frame = Frame(document.leftMargin, document.bottomMargin, document.width, document.height, id="main")
    document.addPageTemplates([PageTemplate(id="main", frames=[frame], onPageEnd=page_footer)])
    story = markdown_story(
        [
            DOCS / "00_READINESS.md",
            DOCS / provider_doc,
            DOCS / "PRODUCTION_CUTOVER_CHECKLIST.md",
        ]
    )
    document.build([KeepTogether(story[:2])] + story[2:])
    return output


if __name__ == "__main__":
    outputs = [
        build("SuperOptic_Emanat_API_v1.pdf", "EMANAT_API_V1.md"),
        build("SuperOptic_UnitedPayment_API_v1.pdf", "UNITED_PAYMENT_API_V1.md"),
    ]
    for output in outputs:
        print(output)
