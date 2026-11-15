"""Иконка school2game: футбольный мяч на раскрытой тетради, синий фон в клетку.
Запуск: python3 tools/make-icons.py android/app/src/main/res [docs/store/icon-512.png]
Пересоздаёт иконки Android всех плотностей (обычная, круглая, передний план для adaptive-icon) и, если указан второй
аргумент, значок 512x512 для магазина (RuStore, Google Play)."""
import math
import os
import sys
from PIL import Image, ImageDraw

BLUE = (35, 71, 201, 255)
GRID = (255, 255, 255, 38)
WHITE = (255, 255, 255, 255)
DARK = (21, 34, 92, 255)
LINE = (170, 190, 235, 255)
ORANGE = (255, 150, 40, 255)
SS = 4  # сглаживание: рисуем в 4 раза крупнее и уменьшаем


def art(d, size, k=1.0):
    """Мяч над раскрытой тетрадью. Координаты в долях стороны, k сжимает рисунок к центру (для безопасной зоны)."""
    def P(x, y):
        return (size / 2 + (x - 0.5) * size * k, size / 2 + (y - 0.5) * size * k)

    def box(x0, y0, x1, y1):
        a, b = P(x0, y0), P(x1, y1)
        return [a[0], a[1], b[0], b[1]]

    w = max(2, int(size * 0.012 * k))
    # тетрадь: две страницы со строчками
    d.rounded_rectangle(box(0.15, 0.55, 0.495, 0.81), radius=size * 0.035 * k, fill=WHITE)
    d.rounded_rectangle(box(0.505, 0.55, 0.85, 0.81), radius=size * 0.035 * k, fill=WHITE)
    for i in range(3):
        y = 0.63 + 0.052 * i
        d.line([P(0.21, y), P(0.44, y)], fill=LINE, width=w)
        d.line([P(0.56, y), P(0.79, y)], fill=LINE, width=w)
    # оранжевые полосы скорости слева от мяча
    for i, (y, x0) in enumerate([(0.26, 0.13), (0.34, 0.09), (0.42, 0.14)]):
        d.line([P(x0, y), P(0.27 + i * 0.01, y)], fill=ORANGE, width=int(size * 0.02 * k))
    # мяч
    cx, cy, r = 0.5, 0.35, 0.2
    d.ellipse(box(cx - r, cy - r, cx + r, cy + r), fill=WHITE, outline=DARK, width=int(size * 0.016 * k))
    pent = []
    for i in range(5):
        a = -math.pi / 2 + i * 2 * math.pi / 5
        pent.append((cx + 0.075 * math.cos(a), cy + 0.075 * math.sin(a)))
    d.polygon([P(*p) for p in pent], fill=DARK)
    for i in range(5):
        a = -math.pi / 2 + i * 2 * math.pi / 5
        d.line([P(cx + 0.075 * math.cos(a), cy + 0.075 * math.sin(a)), P(cx + 0.185 * math.cos(a), cy + 0.185 * math.sin(a))], fill=DARK, width=max(2, int(size * 0.013 * k)))


def background(big, rounded=None):
    bg = Image.new('RGBA', (big, big), BLUE)
    d = ImageDraw.Draw(bg)
    step = big / 6
    for i in range(1, 6):
        d.line([(i * step, 0), (i * step, big)], fill=GRID, width=max(2, SS))
        d.line([(0, i * step), (big, i * step)], fill=GRID, width=max(2, SS))
    return bg


def foreground(size):
    big = size * SS
    img = Image.new('RGBA', (big, big), (0, 0, 0, 0))
    art(ImageDraw.Draw(img), big, 0.78)
    return img.resize((size, size), Image.LANCZOS)


def legacy(size, round_):
    big = size * SS
    img = Image.new('RGBA', (big, big), (0, 0, 0, 0))
    bg = background(big)
    art(ImageDraw.Draw(bg), big, 1.0)
    mask = Image.new('L', (big, big), 0)
    md = ImageDraw.Draw(mask)
    if round_:
        md.ellipse([0, 0, big - 1, big - 1], fill=255)
    else:
        md.rounded_rectangle([0, 0, big - 1, big - 1], radius=int(big * 0.2), fill=255)
    img.paste(bg, (0, 0), mask)
    return img.resize((size, size), Image.LANCZOS)


def store(size=512):
    big = size * SS
    bg = background(big)
    art(ImageDraw.Draw(bg), big, 1.0)
    return bg.convert('RGB').resize((size, size), Image.LANCZOS)


if __name__ == '__main__':
    res = sys.argv[1]
    dens = {'mdpi': (48, 108), 'hdpi': (72, 162), 'xhdpi': (96, 216), 'xxhdpi': (144, 324), 'xxxhdpi': (192, 432)}
    for name, (leg, fg) in dens.items():
        d = os.path.join(res, 'mipmap-' + name)
        legacy(leg, False).save(os.path.join(d, 'ic_launcher.png'))
        legacy(leg, True).save(os.path.join(d, 'ic_launcher_round.png'))
        foreground(fg).save(os.path.join(d, 'ic_launcher_foreground.png'))
    if len(sys.argv) > 2:
        os.makedirs(os.path.dirname(sys.argv[2]) or '.', exist_ok=True)
        store(512).save(sys.argv[2])
    print('icons ok')
