import sys, os
from PIL import Image, ImageDraw
res = sys.argv[1]
BLUE = (35, 71, 201, 255)
GRID = (255, 255, 255, 38)
WHITE = (255, 255, 255, 255)
ORANGE = (255, 178, 90, 255)
SS = 4

def draw_x(d, size, scale=1.0):
    c = size / 2
    arm = size * 0.22 * scale
    w = int(size * 0.095 * scale)
    for a, b in (((c-arm, c-arm), (c+arm, c+arm)), ((c-arm, c+arm), (c+arm, c-arm))):
        d.line([a, b], fill=WHITE, width=w)
        for p in (a, b):
            d.ellipse([p[0]-w/2, p[1]-w/2, p[0]+w/2, p[1]+w/2], fill=WHITE)
    r = size * 0.05 * scale
    d.ellipse([c-r, c-r, c+r, c+r], fill=ORANGE)

def foreground(size):
    big = size * SS
    img = Image.new('RGBA', (big, big), (0, 0, 0, 0))
    draw_x(ImageDraw.Draw(img), big)
    return img.resize((size, size), Image.LANCZOS)

def legacy(size, round_):
    big = size * SS
    img = Image.new('RGBA', (big, big), (0, 0, 0, 0))
    bg = Image.new('RGBA', (big, big), BLUE)
    d = ImageDraw.Draw(bg)
    step = big / 6
    for i in range(1, 6):
        d.line([(i*step, 0), (i*step, big)], fill=GRID, width=max(2, SS))
        d.line([(0, i*step), (big, i*step)], fill=GRID, width=max(2, SS))
    draw_x(d, big, 1.35)
    mask = Image.new('L', (big, big), 0)
    md = ImageDraw.Draw(mask)
    if round_:
        md.ellipse([0, 0, big-1, big-1], fill=255)
    else:
        md.rounded_rectangle([0, 0, big-1, big-1], radius=int(big*0.2), fill=255)
    img.paste(bg, (0, 0), mask)
    return img.resize((size, size), Image.LANCZOS)

dens = {'mdpi': (48, 108), 'hdpi': (72, 162), 'xhdpi': (96, 216), 'xxhdpi': (144, 324), 'xxxhdpi': (192, 432)}
for name, (leg, fg) in dens.items():
    d = os.path.join(res, 'mipmap-' + name)
    legacy(leg, False).save(os.path.join(d, 'ic_launcher.png'))
    legacy(leg, True).save(os.path.join(d, 'ic_launcher_round.png'))
    foreground(fg).save(os.path.join(d, 'ic_launcher_foreground.png'))
print('icons ok')
