import { useEffect, useRef } from 'react';

/**
 * Tore d'aiguilles — décor animé de la page d'accueil.
 *
 * CE QUI PRODUIT L'EFFET, ET QU'UN CERCLE NE DONNE PAS
 * ----------------------------------------------------
 * La figure n'est pas un empilement d'anneaux plats : c'est un TORE échantillonné
 * en 3D, dont chaque point porte une aiguille dressée le long de la normale à la
 * surface, puis projetée en perspective. Trois conséquences, toutes nécessaires :
 *
 *   • la perspective donne au tore son trou et son épaisseur ;
 *   • les aiguilles de la face arrière croisent celles de la face avant, et ce
 *     croisement est exactement le moiré du modèle ;
 *   • la profondeur pilote couleur, opacité et épaisseur, ce qui sépare les deux
 *     faces sans avoir à masquer quoi que ce soit.
 *
 * TRACÉ PAR BANDES DE PROFONDEUR
 * ------------------------------
 * Régler le style aiguille par aiguille coûterait ~2 000 changements d'état de
 * contexte par image. Les aiguilles sont donc rangées dans des bandes de
 * profondeur : une couleur posée par bande, un seul chemin de plusieurs centaines
 * de segments, 26 traits par image au total. Le rangement par bande sert aussi de
 * tri du peintre — le lointain est peint avant le proche, sans jamais trier.
 */
interface TorusFieldProps {
  /** Résolution interne du canevas ; l'affichage se met à l'échelle en CSS. */
  size?: number;
  /** Teinte HSL de base (défaut 263 = violet produit ; 222 = bleu acier landing). */
  hue?: number;
}

export function TorusField({ size = 760, hue = 263 }: TorusFieldProps) {
  const canvasRef = useRef<HTMLCanvasElement>(null);

  useEffect(() => {
    const canvas = canvasRef.current;

    if (canvas === null) {
      return;
    }

    const ctx = canvas.getContext('2d');

    if (ctx === null) {
      return;
    }

    // Plafonné à 2 : au-delà, on quadruple le nombre de pixels pour un gain
    // invisible sur un décor flouté par sa propre opacité.
    const dpr = Math.min(window.devicePixelRatio ?? 1, 2);

    canvas.width = size * dpr;
    canvas.height = size * dpr;
    ctx.scale(dpr, dpr);

    const center = size / 2;
    const majorRadius = size * 0.255;
    const tubeRadius = size * 0.097;
    const quillLength = size * 0.085;
    const focal = size * 1.7;
    const depthSpan = majorRadius + tubeRadius + quillLength;

    const RINGS = 122;
    const PER_RING = 18;
    const BANDS = 26;

    const shafts: number[][] = Array.from({ length: BANDS }, () => []);
    const heads: number[][] = Array.from({ length: BANDS }, () => []);
    const projected = [0, 0, 0];

    let angle = 0.9;
    let frameId = 0;

    // Fonctions fléchées et non déclarations : une `function` est hissée en tête
    // de bloc, donc réputée créée AVANT le test de nullité ci-dessus, et le
    // vérificateur y voit encore un contexte potentiellement nul.
    const render = () => {
      ctx.clearRect(0, 0, size, size);

      for (let band = 0; band < BANDS; band += 1) {
        shafts[band].length = 0;
        heads[band].length = 0;
      }

      // L'inclinaison oscille légèrement : un tore qui ne tourne qu'autour d'un
      // axe se lit comme une image fixe qui glisse, pas comme un volume.
      const tilt = -0.56 + Math.sin(angle * 0.37) * 0.11;
      const cosX = Math.cos(tilt);
      const sinX = Math.sin(tilt);
      const cosY = Math.cos(angle);
      const sinY = Math.sin(angle);

      const project = (x: number, y: number, z: number) => {
        const rx = x * cosY + z * sinY;
        const rz = z * cosY - x * sinY;
        const ry = y * cosX - rz * sinX;
        const depth = y * sinX + rz * cosX;
        const scale = focal / (focal + depth);

        projected[0] = rx * scale + center;
        projected[1] = ry * scale + center;
        projected[2] = depth;
      };

      for (let i = 0; i < RINGS; i += 1) {
        const u = (i / RINGS) * Math.PI * 2;
        const cosU = Math.cos(u);
        const sinU = Math.sin(u);

        for (let j = 0; j < PER_RING; j += 1) {
          // La vrille `u * 0.7` décale les aiguilles d'un anneau au suivant.
          // Sans elle, elles s'alignent en colonnes et la figure ressemble à un
          // ressort ; c'est ce décalage qui fait apparaître les hachures.
          const v = (j / PER_RING) * Math.PI * 2 + u * 0.7;
          const cosV = Math.cos(v);
          const sinV = Math.sin(v);

          const baseX = (majorRadius + tubeRadius * cosV) * cosU;
          const baseY = (majorRadius + tubeRadius * cosV) * sinU;
          const baseZ = tubeRadius * sinV;

          project(baseX, baseY, baseZ);
          const x1 = projected[0];
          const y1 = projected[1];

          project(
            baseX + cosV * cosU * quillLength,
            baseY + cosV * sinU * quillLength,
            baseZ + sinV * quillLength,
          );
          const x2 = projected[0];
          const y2 = projected[1];

          const normalized = (projected[2] + depthSpan) / (depthSpan * 2);
          const band = Math.min(BANDS - 1, Math.max(0, (normalized * BANDS) | 0));

          shafts[band].push(x1, y1, x2, y2);
          heads[band].push(x2, y2);
        }
      }

      for (let band = 0; band < BANDS; band += 1) {
        const shaft = shafts[band];

        if (shaft.length === 0) {
          continue;
        }

        // 0 = face arrière, 1 = face avant. Teinte dérivée de `hue`.
        const nearness = band / (BANDS - 1);

        ctx.strokeStyle = `hsl(${hue + nearness * 9}, ${62 + nearness * 18}%, ${28 + nearness * 40}%)`;
        ctx.globalAlpha = 0.14 + nearness * 0.66;
        ctx.lineWidth = 0.45 + nearness * 0.8;
        ctx.lineCap = 'butt';
        ctx.beginPath();

        for (let k = 0; k < shaft.length; k += 4) {
          ctx.moveTo(shaft[k], shaft[k + 1]);
          ctx.lineTo(shaft[k + 2], shaft[k + 3]);
        }

        ctx.stroke();

        // La tête de l'aiguille — un segment quasi nul en bout rond. C'est ce
        // point plus dense qui donne l'aspect « épingle » plutôt que « rayon ».
        const head = heads[band];

        ctx.lineWidth = 1 + nearness * 1.8;
        ctx.lineCap = 'round';
        ctx.beginPath();

        for (let k = 0; k < head.length; k += 2) {
          ctx.moveTo(head[k], head[k + 1]);
          ctx.lineTo(head[k] + 0.1, head[k + 1]);
        }

        ctx.stroke();
      }

      ctx.globalAlpha = 1;
    };

    // Le mouvement est lent à dessein : un tour complet dure environ trente
    // secondes. Plus rapide, le décor capte le regard que le formulaire réclame.
    const loop = () => {
      angle += 0.0026;
      render();
      frameId = requestAnimationFrame(loop);
    };

    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      render();

      return;
    }

    // Un onglet en arrière-plan ne rend rien : le navigateur suspend déjà
    // requestAnimationFrame, mais l'annulation explicite évite qu'une image en
    // attente reparte au retour et fasse sauter l'angle.
    const onVisibilityChange = () => {
      cancelAnimationFrame(frameId);

      if (!document.hidden) {
        frameId = requestAnimationFrame(loop);
      }
    };

    document.addEventListener('visibilitychange', onVisibilityChange);
    frameId = requestAnimationFrame(loop);

    return () => {
      cancelAnimationFrame(frameId);
      document.removeEventListener('visibilitychange', onVisibilityChange);
    };
  }, [size, hue]);

  return <canvas ref={canvasRef} />;
}
