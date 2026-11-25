"""Illustrations originales du site (libres de droits, créées pour l'ASIJ).
Génère public/assets/img/illustrations/<nom>.svg — python3 resources/illustrations/generate.py"""
import os
OUT = os.path.join(os.path.dirname(__file__), '..', '..', 'public', 'assets', 'img', 'illustrations')
R, O, Y, G, S, V, P = '#d7263d', '#f08a24', '#f6c445', '#43a047', '#2f8fd8', '#8e44ad', '#e2508a'
NAVY, NAVY2, LINE, SOFT = '#1f3566', '#2a447c', '#d9dfeb', '#e9eef8'
SKIN = ['#f1c7a5', '#c98d63', '#e8b48f', '#8d5a3b']
HAIR = ['#3b2a20', '#1d1d1d', '#7a4a22', '#c9a063']

def frame(body, tint):
    return (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 480 360" role="img">'
            f'<rect width="480" height="360" rx="28" fill="{tint}"/>'
            f'<circle cx="390" cy="70" r="26" fill="{Y}" opacity=".9"/>'
            f'<path d="M40 92 a22 22 0 0 1 40 -8 a16 16 0 0 1 26 12 h-66z" fill="#fff" opacity=".85"/>'
            f'<path d="M0 290 C110 250 200 275 300 262 S430 240 480 258 V332 a28 28 0 0 1 -28 28 H28 a28 28 0 0 1 -28 -28z" fill="#fff" opacity=".55"/>'
            + body + '</svg>')

def kid(x, y, c, i, sc=1.0):
    sk, hr = SKIN[i % 4], HAIR[i % 4]
    return (f'<g transform="translate({x},{y}) scale({sc})">'
            f'<path d="M-26 70 C-26 22 -16 8 0 8 C16 8 26 22 26 70Z" fill="{c}"/>'
            f'<rect x="-16" y="66" width="12" height="34" rx="5" fill="{NAVY}"/><rect x="4" y="66" width="12" height="34" rx="5" fill="{NAVY}"/>'
            f'<circle cx="0" cy="-12" r="22" fill="{sk}"/>'
            f'<path d="M-23 -12 C-24 -42 24 -42 23 -12 C14 -26 -14 -26 -23 -12Z" fill="{hr}"/>'
            f'<circle cx="-8" cy="-11" r="2.6" fill="{NAVY}"/><circle cx="8" cy="-11" r="2.6" fill="{NAVY}"/>'
            f'<path d="M-7 -2 Q0 4 7 -2" stroke="{NAVY}" stroke-width="2.6" fill="none" stroke-linecap="round"/></g>')

def balloon(x, y, c, s=1.0):
    return (f'<g transform="translate({x},{y}) scale({s})"><path d="M0 34 C-4 60 10 80 0 110" stroke="#9aa7c0" stroke-width="2" fill="none"/>'
            f'<ellipse cx="0" cy="0" rx="26" ry="32" fill="{c}"/><ellipse cx="-9" cy="-12" rx="6" ry="9" fill="#fff" opacity=".45"/></g>')

ART = {}
# Présentation : l'équipe et ses ballons
ART['people'] = frame(balloon(150, 70, R) + balloon(240, 52, S) + balloon(330, 72, G)
    + kid(150, 200, O, 0) + kid(240, 190, V, 1, 1.1) + kid(330, 200, Y, 2), '#e3effb')
# Bâtiments scolaires
ART['school'] = frame(
    f'<rect x="110" y="140" width="260" height="140" rx="10" fill="#fff" stroke="{LINE}" stroke-width="4"/>'
    f'<path d="M95 148 L240 70 L385 148Z" fill="{R}"/><rect x="226" y="40" width="4" height="36" fill="{NAVY}"/><path d="M230 42 h34 l-8 9 l8 9 h-34z" fill="{O}"/>'
    + ''.join(f'<rect x="{x}" y="{y}" width="38" height="32" rx="5" fill="#cfe2f7" stroke="{S}" stroke-width="3"/>' for x in (135, 190, 252, 307) for y in (165, 220) if not (x in (190, 252) and y == 220))
    + f'<rect x="210" y="222" width="60" height="58" rx="6" fill="{NAVY2}"/><circle cx="240" cy="110" r="16" fill="#fff" stroke="{NAVY}" stroke-width="3"/><path d="M240 101 v9 l6 5" stroke="{NAVY}" stroke-width="3" fill="none" stroke-linecap="round"/>'
    + f'<circle cx="80" cy="250" r="30" fill="{G}"/><rect x="76" y="260" width="8" height="30" fill="#7a4a22"/><circle cx="410" cy="245" r="34" fill="{G}"/><rect x="406" y="258" width="8" height="32" fill="#7a4a22"/>', '#fdecee')
# Transports scolaires : bus
ART['bus'] = frame(
    f'<rect x="70" y="140" width="320" height="120" rx="26" fill="{Y}"/><rect x="70" y="226" width="320" height="16" fill="{O}"/>'
    + ''.join(f'<rect x="{x}" y="160" width="52" height="44" rx="8" fill="#cfe2f7"/>' for x in (95, 160, 225))
    + f'<rect x="300" y="160" width="68" height="60" rx="10" fill="#cfe2f7"/><rect x="290" y="180" width="6" height="60" fill="{NAVY}" opacity=".25"/>'
    + kid(121, 190, R, 0, .55) + kid(186, 190, G, 1, .55) + kid(251, 190, V, 3, .55)
    + f'<rect x="70" y="160" width="320" height="0"/><circle cx="135" cy="262" r="26" fill="{NAVY}"/><circle cx="135" cy="262" r="10" fill="#cfd6e4"/><circle cx="330" cy="262" r="26" fill="{NAVY}"/><circle cx="330" cy="262" r="10" fill="#cfd6e4"/>'
    + f'<circle cx="384" cy="236" r="7" fill="#fff"/><rect x="420" y="150" width="8" height="140" fill="#9aa7c0"/><rect x="404" y="128" width="40" height="40" rx="8" fill="{S}"/><path d="M414 138 h20 v16 h-20z" fill="#fff"/>', '#fff6dc')
# Accueil extrascolaire / réfectoire
ART['meal'] = frame(
    f'<ellipse cx="240" cy="250" rx="190" ry="34" fill="{NAVY2}"/>'
    + kid(150, 180, S, 0) + kid(240, 170, P, 2) + kid(330, 180, G, 1)
    + f'<rect x="50" y="230" width="380" height="24" rx="12" fill="{NAVY}"/>'
    + ''.join(f'<ellipse cx="{x}" cy="228" rx="30" ry="9" fill="#fff"/><circle cx="{x}" cy="222" r="12" fill="{c}"/>' for x, c in ((150, O), (240, G), (330, R)))
    + f'<path d="M100 216 v20 M96 216 v8 M104 216 v8" stroke="#9aa7c0" stroke-width="3" stroke-linecap="round"/><rect x="372" y="206" width="22" height="28" rx="5" fill="#fff"/>', '#e8f5e9')
# Conseil d'établissement : dialogue
ART['talk'] = frame(
    f'<rect x="70" y="60" width="200" height="90" rx="26" fill="#fff" stroke="{LINE}" stroke-width="4"/><path d="M120 148 l10 26 l20 -26z" fill="#fff"/>'
    + ''.join(f'<circle cx="{x}" cy="105" r="10" fill="{V}"/>' for x in (130, 170, 210))
    + f'<rect x="230" y="96" width="190" height="80" rx="26" fill="{V}"/><path d="M370 174 l-6 24 l-18 -24z" fill="{V}"/><path d="M276 136 l14 14 l30 -30" stroke="#fff" stroke-width="9" fill="none" stroke-linecap="round" stroke-linejoin="round"/><rect x="332" y="124" width="64" height="9" rx="4" fill="#fff" opacity=".7"/><rect x="332" y="142" width="44" height="9" rx="4" fill="#fff" opacity=".7"/>'
    + kid(140, 236, O, 0, .9) + kid(360, 236, S, 3, .9) + kid(250, 246, G, 2, .8), '#f1e6f7')
# Liens utiles
ART['link'] = frame(
    f'<rect x="90" y="70" width="300" height="200" rx="18" fill="#fff" stroke="{LINE}" stroke-width="4"/><rect x="90" y="70" width="300" height="34" rx="18" fill="{SOFT}"/>'
    + ''.join(f'<circle cx="{x}" cy="87" r="6" fill="{c}"/>' for x, c in ((114, R), (134, Y), (154, G)))
    + f'<circle cx="190" cy="186" r="52" fill="none" stroke="{S}" stroke-width="8"/><path d="M138 186 h104 M190 134 c-26 30 -26 74 0 104 M190 134 c26 30 26 74 0 104" stroke="{S}" stroke-width="6" fill="none"/>'
    + f'<g transform="translate(300 180) rotate(-35)"><rect x="-44" y="-16" width="52" height="32" rx="16" fill="none" stroke="{O}" stroke-width="10"/><rect x="-8" y="-16" width="52" height="32" rx="16" fill="none" stroke="{R}" stroke-width="10"/></g>', '#e3effb')
# En cours : chantier
ART['works'] = frame(
    f'<rect x="240" y="60" width="10" height="210" fill="{O}"/><rect x="150" y="60" width="220" height="10" fill="{O}"/><path d="M245 70 l-20 30 h40z" fill="{O}" opacity=".6"/>'
    f'<path d="M340 70 v60" stroke="{NAVY}" stroke-width="3"/><rect x="320" y="130" width="40" height="30" rx="4" fill="{S}"/>'
    f'<rect x="80" y="180" width="140" height="90" fill="#fff" stroke="{LINE}" stroke-width="4"/><path d="M80 210 h140 M80 240 h140 M126 180 v90 M173 180 v90" stroke="{LINE}" stroke-width="4"/>'
    + ''.join(f'<path d="M{x} 290 l18 -50 l18 50z" fill="{O}"/><rect x="{x+6}" y="262" width="24" height="6" fill="#fff"/>' for x in (290, 350))
    + kid(420, 200, Y, 3, .8) + f'<path d="M398 172 a22 16 0 0 1 44 0z" fill="{Y}"/>', '#fff1e0')
# Contact
ART['mail'] = frame(
    f'<rect x="110" y="110" width="260" height="170" rx="16" fill="#fff" stroke="{LINE}" stroke-width="4"/><path d="M114 118 L240 210 L366 118" stroke="{S}" stroke-width="8" fill="none" stroke-linejoin="round"/>'
    f'<path d="M180 70 l40 30 l-8 -42z" fill="{R}"/><path d="M110 280 l100 -70 M370 280 l-100 -70" stroke="{LINE}" stroke-width="4"/>'
    f'<circle cx="370" cy="120" r="30" fill="{G}"/><path d="M358 112 c0 12 10 22 22 22 l6 -6 l-8 -8 l-5 4 c-4 -2 -7 -5 -9 -9 l4 -5 l-8 -8z" fill="#fff"/>', '#e3effb')
# Actualités
ART['news'] = frame(
    f'<rect x="100" y="70" width="280" height="210" rx="14" fill="#fff" stroke="{LINE}" stroke-width="4"/><rect x="124" y="94" width="160" height="16" rx="8" fill="{NAVY}"/>'
    f'<rect x="124" y="126" width="110" height="80" rx="10" fill="#cfe2f7"/><path d="M124 190 l30 -26 l24 18 l18 -12 l38 30 v6 h-110z" fill="{G}"/><circle cx="210" cy="148" r="9" fill="{Y}"/>'
    + ''.join(f'<rect x="250" y="{y}" width="{w}" height="9" rx="4" fill="{LINE}"/>' for y, w in ((130, 106), (150, 90), (170, 106), (190, 70)))
    + ''.join(f'<rect x="124" y="{y}" width="{w}" height="9" rx="4" fill="{LINE}"/>' for y, w in ((224, 232), (244, 200)))
    + balloon(400, 110, P, .8), '#fdecf3')
# Documents
ART['doc'] = frame(
    f'<path d="M150 60 h130 l50 50 v170 h-180z" fill="#fff" stroke="{LINE}" stroke-width="4"/><path d="M280 60 v50 h50" fill="none" stroke="{LINE}" stroke-width="4"/>'
    + ''.join(f'<rect x="176" y="{y}" width="{w}" height="10" rx="5" fill="{LINE}"/>' for y, w in ((140, 130), (164, 110), (188, 130), (212, 90)))
    + f'<rect x="176" y="236" width="64" height="26" rx="6" fill="{R}"/><text x="208" y="255" font-family="Arial" font-size="16" font-weight="700" fill="#fff" text-anchor="middle">PDF</text>', '#eef1f7')
ART['balloon'] = frame(balloon(190, 120, R, 1.3) + balloon(260, 96, S, 1.4) + balloon(320, 130, G, 1.2), '#e3effb')

os.makedirs(OUT, exist_ok=True)
for name, svg in ART.items():
    with open(os.path.join(OUT, name + '.svg'), 'w') as f:
        f.write(svg)
print(len(ART), 'illustrations')
