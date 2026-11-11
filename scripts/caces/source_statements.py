"""Extract statement cells, keeping PDF pagination for shared illustrations.

The answer columns have horizontal rules even when an illustration spans several
statement rows. Read those rules rather than splitting at question-number midpoints.
"""
import pymupdf as fitz

# Four source cells have no printed number. Coordinates verified against the PDFs.
MISSING_NUMBERS = {
    ('483', 1, 6): (29, 135),
    ('484', 4, 3): (15, 228),
    ('490', 1, 4): (15, 624),
    ('490', 5, 3): (22, 559),
}


def statement_cells(page, recommendation, variant, page_number):
    nums = [w for w in page.get_text('words')
            if w[0] < 34 and w[4].isdigit() and w[1] > 35]
    # These two numbers label a heading / shared diagram, not another statement.
    if (recommendation, variant, page_number) == ('486', 4, 2):
        nums = [w for w in nums if not (w[4] == '5' and 535 < w[1] < 550)]
    if (recommendation, variant, page_number) == ('490', 4, 4):
        nums = [w for w in nums if not (w[4] == '26' and w[1] < 300)]
    missing = MISSING_NUMBERS.get((recommendation, variant, page_number))
    if missing:
        number, y = missing
        nums.append((22, y - 5, 30, y + 5, str(number)))
    if not nums:
        return []

    pix = page.get_pixmap(colorspace=fitz.csGRAY)
    samples = pix.samples
    boundaries = []
    for y in range(35, pix.height - 12):
        row = samples[y * pix.stride + 510:y * pix.stride + 573]
        if sum(value < 190 for value in row) > len(row) * .5:
            if not boundaries or y - boundaries[-1] > 2:
                boundaries.append(y)
            else:
                boundaries[-1] = y
    # Subheadings may share a merged answer cell. Their coloured rectangles
    # supply precise boundaries, so their text cannot leak into a statement.
    for drawing in page.get_drawings():
        rect = drawing['rect']
        if drawing.get('fill') and rect.width > 400 and 2 < rect.height < 60:
            boundaries.extend((rect.y0, rect.y1))

    cells = []
    for number in sorted(nums, key=lambda w: w[1]):
        center = (number[1] + number[3]) / 2
        top = max((y for y in boundaries if y < center), default=35)
        bottom = min((y for y in boundaries if y > center), default=page.rect.height - 15)
        label = ' '.join(page.get_text(clip=fitz.Rect(35, top + .5, 508, bottom), sort=True).split())
        if not label or '●' in label:
            raise ValueError((recommendation, variant, page_number, number[4], label))
        cells.append((int(number[4]), label))
    return cells
