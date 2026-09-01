#!/usr/bin/env python3
"""Build an MP4 from images and text, using ffmpeg + ImageMagick. Runs on the manager server.

    make-video.py <spec.json> <out.mp4>

Spec (paths are relative to the spec file):

    {
      "size":  "1080x1920",        # 1080x1920 vertical, 1920x1080 landscape, 1080x1080 square
      "fps":   30,
      "bg":    "#0b1220",          # background for scenes without an image
      "audio": "music.m4a",        # optional, looped and faded out at the end
      "scenes": [
        {"image": "a.jpg", "seconds": 3.5, "title": "Dòng lớn", "text": "Dòng nhỏ", "zoom": "in"},
        {"title": "Chỉ có chữ"}
      ]
    }

Why it is built this way:
  * Text is rendered by ImageMagick, not ffmpeg's drawtext: it wraps Vietnamese automatically and
    the glyphs come out right. The text layer is a static PNG laid over the moving background, so
    the Ken Burns zoom moves the photo without dragging the words along with it.
  * Each scene is encoded to its own file and the files are concatenated afterwards. ffmpeg 4.2 on
    this server has no `xfade`, so scenes fade through the background colour instead of into each
    other, and a per-scene pass stays readable and debuggable when one scene misbehaves.
"""
import json, os, re, shlex, subprocess, sys, tempfile

def _font(*names):
    """DejaVu sits in a different directory on Alpine than on Debian/Ubuntu, and this script runs
    on both (the api/horizon image and the host). Pick the first one that actually exists."""
    roots = ("/usr/share/fonts/truetype/dejavu", "/usr/share/fonts/dejavu",
             "/usr/share/fonts/TTF", "/usr/share/fonts")
    for root in roots:
        for n in names:
            p = os.path.join(root, n)
            if os.path.exists(p):
                return p
    sys.exit("LỖI: không tìm thấy font %s. Cài gói font DejaVu." % (names,))


FONT_BOLD = _font("DejaVuSans-Bold.ttf")
FONT_BODY = _font("DejaVuSans.ttf")
FADE = 0.4          # seconds of fade in and out on every scene


def run(cmd, **kw):
    p = subprocess.run(cmd, capture_output=True, text=True, **kw)
    if p.returncode != 0:
        sys.exit("LỖI: %s\n%s" % (" ".join(shlex.quote(c) for c in cmd[:6]) + " …", p.stderr[-1500:]))
    return p.stdout


def png_height(path):
    return int(run(["identify", "-format", "%h", path]).strip())


def text_layer(scene, W, H, work, idx):
    """Transparent PNG with a bottom scrim, the body text, and the title above it."""
    title = (scene.get("title") or "").strip()
    body = (scene.get("text") or "").strip()
    out = os.path.join(work, "txt%03d.png" % idx)
    margin = int(W * 0.07)
    inner = W - 2 * margin

    cmd = ["convert", "-size", "%dx%d" % (W, H), "xc:none"]
    if title or body:
        # scrim: a gradient that is clear at the top and dark at the bottom, so text stays readable
        # over a bright photo without dimming the whole picture
        cmd += ["(", "-size", "%dx%d" % (W, H), "gradient:none-black",
                "-channel", "A", "-evaluate", "multiply", "0.8", "+channel", ")",
                "-composite"]

    y = margin
    if body:
        bpng = os.path.join(work, "body%03d.png" % idx)
        run(["convert", "-background", "none", "-fill", "#e6edf7", "-font", FONT_BODY,
             "-pointsize", str(max(18, int(H / 34))), "-interline-spacing", str(int(H / 120)),
             "-size", "%dx" % inner, "caption:%s" % body, bpng])
        cmd += ["(", bpng, ")", "-gravity", "SouthWest", "-geometry", "+%d+%d" % (margin, y), "-composite"]
        y += png_height(bpng) + int(H * 0.02)

    if title:
        tpng = os.path.join(work, "title%03d.png" % idx)
        run(["convert", "-background", "none", "-fill", "white", "-font", FONT_BOLD,
             "-pointsize", str(max(26, int(H / 18))), "-interline-spacing", str(int(H / 90)),
             "-size", "%dx" % inner, "caption:%s" % title, tpng])
        cmd += ["(", tpng, ")", "-gravity", "SouthWest", "-geometry", "+%d+%d" % (margin, y), "-composite"]

    cmd.append(out)
    run(cmd)
    return out


def scene_clip(scene, i, W, H, fps, bg, base, work):
    dur = float(scene.get("seconds") or 3.5)
    frames = max(1, int(round(dur * fps)))
    overlay = text_layer(scene, W, H, work, i)
    out = os.path.join(work, "clip%03d.mp4" % i)
    img = scene.get("image")

    if img:
        src = img if os.path.isabs(img) else os.path.join(base, img)
        if not os.path.exists(src):
            sys.exit("LỖI: không thấy ảnh %s (cảnh %d)" % (src, i + 1))
        # Oversample before zoompan: zooming a canvas-sized frame makes the motion visibly jerky.
        # 1.5x is the sweet spot. The zoom only ever reaches 1.15, so more than that buys no
        # sharpness and costs a lot: this server has no GPU, and 4x oversampling on a 1080x1920
        # canvas meant 33 megapixels per frame, which took minutes per scene.
        OW, OH = int(W * 1.5) // 2 * 2, int(H * 1.5) // 2 * 2
        pre = "scale=%d:%d:force_original_aspect_ratio=increase,crop=%d:%d" % (OW, OH, OW, OH)
        z = "'min(zoom+0.0009,1.15)'" if scene.get("zoom", "in") == "in" else "'if(lte(zoom,1.0),1.15,max(1.001,zoom-0.0009))'"
        vf = "%s,zoompan=z=%s:d=%d:s=%dx%d:fps=%d" % (pre, z, frames, W, H, fps)
        # ONE input frame, never `-loop 1 -t`: zoompan emits d= frames for EVERY frame it is fed.
        # Looping the still first produced 105 identical frames, so zoompan turned a 3.5s scene
        # into 105x105 = 11025 frames. That is what made the first runs crawl for minutes.
        inputs = ["-i", src]
    else:
        vf = "null"
        inputs = ["-f", "lavfi", "-i", "color=c=%s:s=%dx%d:r=%d" % (bg, W, H, fps)]

    fo = max(0.0, dur - FADE)
    chain = ("[0:v]%s,format=rgba[bgv];"
             "[bgv][1:v]overlay=0:0:format=auto,"
             "fade=t=in:st=0:d=%.2f,fade=t=out:st=%.2f:d=%.2f,format=yuv420p[v]") % (vf, FADE, fo, FADE)

    run(["ffmpeg", "-y", "-hide_banner", "-loglevel", "error", *inputs, "-loop", "1", "-i", overlay,
         "-filter_complex", chain, "-map", "[v]", "-t", "%.3f" % dur,
         "-c:v", "libx264", "-preset", "veryfast", "-crf", "21", "-r", str(fps),
         "-pix_fmt", "yuv420p", out])
    return out, dur


def main(argv):
    if len(argv) < 3:
        print(__doc__); return 2
    spec_path, out_path = argv[1], argv[2]
    spec = json.load(open(spec_path, encoding="utf-8"))
    base = os.path.dirname(os.path.abspath(spec_path))

    m = re.match(r"^(\d+)x(\d+)$", str(spec.get("size") or "1080x1920"))
    if not m:
        sys.exit('LỖI: "size" phải dạng RỘNGxCAO, ví dụ 1080x1920')
    W, H = int(m.group(1)), int(m.group(2))
    W, H = W - W % 2, H - H % 2                      # libx264 needs even dimensions
    fps = int(spec.get("fps") or 30)
    bg = spec.get("bg") or "#0b1220"
    scenes = spec.get("scenes") or []
    if not scenes:
        sys.exit('LỖI: "scenes" trống')

    work = tempfile.mkdtemp(prefix="mkvideo-")
    clips, total = [], 0.0
    for i, sc in enumerate(scenes):
        clip, dur = scene_clip(sc, i, W, H, fps, bg, base, work)
        clips.append(clip); total += dur
        print("cảnh %d/%d xong (%.1fs)" % (i + 1, len(scenes), dur), flush=True)

    listfile = os.path.join(work, "clips.txt")
    with open(listfile, "w", encoding="utf-8") as fh:
        for c in clips:
            fh.write("file '%s'\n" % c)

    audio = spec.get("audio")
    cmd = ["ffmpeg", "-y", "-hide_banner", "-loglevel", "error",
           "-f", "concat", "-safe", "0", "-i", listfile]
    if audio:
        src = audio if os.path.isabs(audio) else os.path.join(base, audio)
        if not os.path.exists(src):
            sys.exit("LỖI: không thấy file nhạc %s" % src)
        cmd += ["-stream_loop", "-1", "-i", src,
                "-af", "afade=t=out:st=%.2f:d=2" % max(0.0, total - 2),
                "-c:a", "aac", "-b:a", "160k", "-shortest"]
    cmd += ["-c:v", "copy", out_path]     # the scene clips already carry the final encode
    run(cmd)

    size_mb = os.path.getsize(out_path) / 1e6
    print("XONG: %s · %dx%d · %.1fs · %.1f MB" % (out_path, W, H, total, size_mb))
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
