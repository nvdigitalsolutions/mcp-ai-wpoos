//! Vision detector risk spike (Proposal 075).
//!
//! The de-risk step: prove that a YOLOv8/v11 ONNX model actually runs on the
//! app's pinned `ort` rc.9, and confirm the output-shape assumption, before
//! any detector/tracker/UI code is written against it.
//!
//! Usage:
//!   cargo run --release --features vision --example vision_probe -- \
//!       <image> <yolov11n.onnx> [--labels labels.txt] [--conf 0.25] [--iou 0.45]
//!
//! Prints decoded + NMS'd detections `(label, confidence, x, y, w, h)` in
//! source-pixel coords, plus wall-clock inference time.

use std::path::PathBuf;

fn main() -> Result<(), Box<dyn std::error::Error>> {
    let args: Vec<String> = std::env::args().collect();
    if args.len() < 3 {
        eprintln!("usage: vision_probe <image> <model.onnx> [--conf N] [--iou N]");
        std::process::exit(2);
    }
    let image_path = PathBuf::from(&args[1]);
    let model_path = PathBuf::from(&args[2]);
    let mut conf = 0.25f32;
    let mut iou = 0.45f32;
    let mut i = 3;
    while i < args.len() {
        match args[i].as_str() {
            "--conf" => {
                conf = args.get(i + 1).and_then(|s| s.parse().ok()).unwrap_or(conf);
                i += 2;
            }
            "--iou" => {
                iou = args.get(i + 1).and_then(|s| s.parse().ok()).unwrap_or(iou);
                i += 2;
            }
            _ => i += 1,
        }
    }

    // Load + resize the image to the detector's 640×640 letterbox.
    let img = image::open(&image_path)?.to_rgb8();
    let (src_w, src_h) = (img.width(), img.height());
    let (input, scale, pad_x, pad_y) = letterbox(&img, 640, 640);

    // Build the ORT session (the spike's real purpose: does rc.9 run this?).
    let session = ort::session::Session::builder()?.commit_from_file(&model_path)?;
    let input_name = session
        .inputs
        .first()
        .map(|i| i.name.clone())
        .ok_or("model has no inputs")?;

    // CHW float tensor, normalized 0..1.
    let tensor = ndarray16::Array::from_shape_vec((1, 3, 640, 640), input)?.into_dyn();
    let start = std::time::Instant::now();
    // Single positional input — the model has exactly one input, so `inputs!`
    // binds the tensor to it by position (no name lookup needed).
    let outputs = session.run(ort::inputs![ort::value::Value::from_array(tensor)?]?)?;
    let out_tensor = outputs[0]
        .try_extract_tensor::<f32>()
        .map_err(|e| format!("could not extract output tensor: {e}"))?;
    let _ = input_name;

    let shape = out_tensor.shape().to_vec();
    println!("model:      {}", model_path.display());
    println!("input name: {input_name}");
    println!("output shape: {shape:?}");
    println!("inference:  {} ms", start.elapsed().as_millis());

    let dets = decode_yolo(
        out_tensor.view(),
        shape.as_slice(),
        conf,
        iou,
        scale,
        pad_x,
        pad_y,
    );
    println!("detections: {}", dets.len());
    for d in &dets {
        println!(
            "  {:<24} conf={:.2} box=({}, {}, {}, {})",
            d.label, d.confidence, d.x, d.y, d.w, d.h
        );
    }
    println!("source:     {src_w}x{src_h}");
    Ok(())
}

struct Detection {
    label: String,
    confidence: f32,
    x: u32,
    y: u32,
    w: u32,
    h: u32,
}

/// COCO-80 class names (index-aligned) — the stock YOLO label set.
const COCO: [&str; 80] = [
    "person",
    "bicycle",
    "car",
    "motorcycle",
    "airplane",
    "bus",
    "train",
    "truck",
    "boat",
    "traffic light",
    "fire hydrant",
    "stop sign",
    "parking meter",
    "bench",
    "bird",
    "cat",
    "dog",
    "horse",
    "sheep",
    "cow",
    "elephant",
    "bear",
    "zebra",
    "giraffe",
    "backpack",
    "umbrella",
    "handbag",
    "tie",
    "suitcase",
    "frisbee",
    "skis",
    "snowboard",
    "sports ball",
    "kite",
    "baseball bat",
    "baseball glove",
    "skateboard",
    "surfboard",
    "tennis racket",
    "bottle",
    "wine glass",
    "cup",
    "fork",
    "knife",
    "spoon",
    "bowl",
    "banana",
    "apple",
    "sandwich",
    "orange",
    "broccoli",
    "carrot",
    "hot dog",
    "pizza",
    "donut",
    "cake",
    "chair",
    "couch",
    "potted plant",
    "bed",
    "dining table",
    "toilet",
    "tv",
    "laptop",
    "mouse",
    "remote",
    "keyboard",
    "cell phone",
    "microwave",
    "oven",
    "toaster",
    "sink",
    "refrigerator",
    "book",
    "clock",
    "vase",
    "scissors",
    "teddy bear",
    "hair drier",
    "toothbrush",
];

/// Letterbox an RGB image to `w×h`, returning the CHW float tensor plus the
/// scale/pad needed to project boxes back to source pixels.
fn letterbox(img: &image::RgbImage, w: u32, h: u32) -> (Vec<f32>, f32, u32, u32) {
    let (iw, ih) = (img.width(), img.height());
    let scale = (w as f32 / iw as f32).min(h as f32 / ih as f32);
    let (nw, nh) = (
        (iw as f32 * scale).round() as u32,
        (ih as f32 * scale).round() as u32,
    );
    let (pad_x, pad_y) = ((w - nw) / 2, (h - nh) / 2);

    // Precompute the letterboxed image buffer.
    let mut canvas = vec![0u8; (w * h * 3) as usize];
    for y in 0..nh {
        for x in 0..nw {
            let sx = ((x as f32) / scale) as u32;
            let sy = ((y as f32) / scale) as u32;
            let sp = img.get_pixel(sx.min(iw - 1), sy.min(ih - 1));
            let dp = ((y + pad_y) * w + x + pad_x) as usize * 3;
            canvas[dp] = sp[0];
            canvas[dp + 1] = sp[1];
            canvas[dp + 2] = sp[2];
        }
    }

    // HWC → CHW, normalized to 0..1.
    let mut out = Vec::with_capacity((w * h * 3) as usize);
    for c in 0..3 {
        for i in 0..(w * h) as usize {
            out.push(canvas[i * 3 + c] as f32 / 255.0);
        }
    }
    (out, scale, pad_x, pad_y)
}

/// Decode YOLOv8/v11 ONNX output `[1, 4+nc, N]` into source-pixel detections,
/// applying NMS. The output is `cx,cy,w,h` per candidate + class scores.
fn decode_yolo(
    out: ndarray16::ArrayViewD<f32>,
    shape: &[usize],
    conf: f32,
    iou: f32,
    scale: f32,
    pad_x: u32,
    pad_y: u32,
) -> Vec<Detection> {
    // shape = [1, 4+nc, N] (batch=1). nc = shape[1] - 4, N = shape[2].
    let (num_pred, nc) = match shape {
        [1, cols, n, ..] if *cols >= 5 => (*n, cols - 4),
        [cols, n] if *cols >= 5 => (*n, cols - 4), // some exports drop the batch dim
        _ => {
            eprintln!("unexpected output shape {shape:?}; assuming 80 classes, 8400 preds");
            (8400, 80)
        }
    };

    let mut boxes: Vec<Detection> = Vec::new();
    for p in 0..num_pred {
        // Class with the highest score.
        let mut best_class = 0usize;
        let mut best_score = 0.0f32;
        for c in 0..nc {
            let s = out[[0, 4 + c, p]];
            if s > best_score {
                best_score = s;
                best_class = c;
            }
        }
        if best_score < conf {
            continue;
        }
        let (cx, cy, bw, bh) = (
            out[[0, 0, p]],
            out[[0, 1, p]],
            out[[0, 2, p]],
            out[[0, 3, p]],
        );
        // Letterbox → source pixels.
        let x = ((cx - bw / 2.0 - pad_x as f32) / scale).max(0.0) as u32;
        let y = ((cy - bh / 2.0 - pad_y as f32) / scale).max(0.0) as u32;
        let w = (bw / scale) as u32;
        let h = (bh / scale) as u32;
        let label = COCO.get(best_class).copied().unwrap_or("?").to_string();
        boxes.push(Detection {
            label,
            confidence: best_score,
            x,
            y,
            w,
            h,
        });
    }

    // Non-max suppression: sort by confidence desc, suppress overlapping boxes.
    boxes.sort_by(|a, b| b.confidence.partial_cmp(&a.confidence).unwrap());
    let mut keep: Vec<Detection> = Vec::new();
    for b in boxes {
        let mut suppressed = false;
        for k in &keep {
            if iou_of(&b, k) > iou {
                suppressed = true;
                break;
            }
        }
        if !suppressed {
            keep.push(b);
        }
    }
    keep
}

fn iou_of(a: &Detection, b: &Detection) -> f32 {
    let ax1 = a.x as f32;
    let ay1 = a.y as f32;
    let ax2 = ax1 + a.w as f32;
    let ay2 = ay1 + a.h as f32;
    let bx1 = b.x as f32;
    let by1 = b.y as f32;
    let bx2 = bx1 + b.w as f32;
    let by2 = by1 + b.h as f32;
    let ix1 = ax1.max(bx1);
    let iy1 = ay1.max(by1);
    let ix2 = ax2.min(bx2);
    let iy2 = ay2.min(by2);
    let inter = (ix2 - ix1).max(0.0) * (iy2 - iy1).max(0.0);
    let a_area = (ax2 - ax1) * (ay2 - ay1);
    let b_area = (bx2 - bx1) * (by2 - by1);
    let union = a_area + b_area - inter;
    if union <= 0.0 {
        0.0
    } else {
        inter / union
    }
}
