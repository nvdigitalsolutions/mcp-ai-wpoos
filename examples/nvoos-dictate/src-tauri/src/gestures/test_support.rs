// SPDX-License-Identifier: LicenseRef-Proprietary
//! Synthetic hand fixtures for unit tests.
//!
//! These build plausible 21-landmark hands without a camera or a model, so
//! every decision layer (normalise → geometry → classify → stabilise → route)
//! can be tested deterministically. Coordinates are in a "local" frame with
//! the wrist at the origin and fingers pointing to −y; helpers apply
//! translation/scale/rotation to emulate a real frame.

#![cfg(test)]

use crate::gestures::Point3;

/// Landmark indices, named for readability in assertions.
pub const WRIST: usize = 0;
pub const THUMB_CMC: usize = 1;
pub const THUMB_MCP: usize = 2;
pub const THUMB_IP: usize = 3;
pub const THUMB_TIP: usize = 4;
pub const INDEX_MCP: usize = 5;
pub const PINKY_MCP: usize = 17;

/// Build an open palm (all five extended, fingers together).
pub fn open_palm() -> [Point3; 21] {
    build(HandSpec {
        extended: [true, true, true, true, true],
        splay: 0.02,
        thumb: Thumb::Abducted,
    })
}

/// An open palm with fingers widely spread — the "stop" gesture.
pub fn stop_hand() -> [Point3; 21] {
    build(HandSpec {
        extended: [true, true, true, true, true],
        splay: 0.60,
        thumb: Thumb::Abducted,
    })
}

/// A pose between "fingers together" and "spread" — the ambiguity band the
/// stabiliser and the T2 arbiter exist for.
pub fn half_spread() -> [Point3; 21] {
    build(HandSpec {
        extended: [true, true, true, true, true],
        splay: 0.33,
        thumb: Thumb::Abducted,
    })
}

/// All four fingers curled, thumb folded across the palm.
pub fn closed_fist() -> [Point3; 21] {
    build(HandSpec {
        extended: [false, false, false, false, false],
        splay: 0.0,
        thumb: Thumb::Curled,
    })
}

/// Index only.
pub fn pointing_up() -> [Point3; 21] {
    build(HandSpec {
        extended: [false, true, false, false, false],
        splay: 0.02,
        thumb: Thumb::Curled,
    })
}

/// Index + middle.
pub fn victory() -> [Point3; 21] {
    build(HandSpec {
        extended: [false, true, true, false, false],
        splay: 0.15,
        thumb: Thumb::Curled,
    })
}

/// Thumb up, four fingers curled.
pub fn thumb_up() -> [Point3; 21] {
    build(HandSpec {
        extended: [true, false, false, false, false],
        splay: 0.0,
        thumb: Thumb::Up,
    })
}

/// Thumb down, four fingers curled.
pub fn thumb_down() -> [Point3; 21] {
    build(HandSpec {
        extended: [true, false, false, false, false],
        splay: 0.0,
        thumb: Thumb::Down,
    })
}

/// Pinch: thumb tip meets the index tip.
pub fn pinch() -> [Point3; 21] {
    let mut hand = build(HandSpec {
        extended: [false, true, false, false, false],
        splay: 0.02,
        thumb: Thumb::Abducted,
    });
    let index_tip = hand[8];
    hand[THUMB_TIP] = Point3::new(index_tip.x - 0.04, index_tip.y + 0.03, index_tip.z);
    hand[THUMB_IP] = Point3::new(index_tip.x - 0.12, index_tip.y + 0.10, index_tip.z);
    hand
}

/// Apply translation, uniform scale, and in-plane rotation (radians).
pub fn transform(
    points: &[Point3; 21],
    tx: f32,
    ty: f32,
    scale: f32,
    rotation: f32,
) -> [Point3; 21] {
    let (sin, cos) = rotation.sin_cos();
    let mut out = [Point3::default(); 21];
    for (i, p) in points.iter().enumerate() {
        let x = p.x * scale;
        let y = p.y * scale;
        out[i] = Point3::new(x * cos - y * sin + tx, x * sin + y * cos + ty, p.z);
    }
    out
}

/// The palm centre used by the motion stage (mean of wrist + four knuckles).
pub fn palm_center(points: &[Point3; 21]) -> Point3 {
    let mut x = 0.0;
    let mut y = 0.0;
    let mut z = 0.0;
    for i in [WRIST, 5, 9, 13, 17] {
        x += points[i].x;
        y += points[i].y;
        z += points[i].z;
    }
    Point3::new(x / 5.0, y / 5.0, z / 5.0)
}

struct HandSpec {
    /// [thumb, index, middle, ring, pinky]
    extended: [bool; 5],
    /// Lateral fan applied to the extended fingers (0 = together, 0.4 = wide).
    splay: f32,
    thumb: Thumb,
}

enum Thumb {
    Abducted,
    Curled,
    Up,
    Down,
}

fn build(spec: HandSpec) -> [Point3; 21] {
    let mut p = [Point3::default(); 21];

    // Knuckles: an arc across the palm, wrist→middle-MCP along −y.
    p[INDEX_MCP] = Point3::new(-0.34, -0.56, 0.0);
    p[9] = Point3::new(-0.10, -0.63, 0.0); // middle MCP
    p[13] = Point3::new(0.12, -0.60, 0.0); // ring MCP
    p[PINKY_MCP] = Point3::new(0.33, -0.52, 0.0);

    // Thumb chain (before the per-pose override).
    p[THUMB_CMC] = Point3::new(0.16, -0.16, 0.0);
    p[THUMB_MCP] = Point3::new(0.30, -0.24, 0.0);
    p[THUMB_IP] = Point3::new(0.40, -0.34, 0.0);
    p[THUMB_TIP] = Point3::new(0.48, -0.44, 0.0);

    match spec.thumb {
        Thumb::Abducted => {}
        Thumb::Curled => {
            p[THUMB_MCP] = Point3::new(0.20, -0.20, 0.0);
            p[THUMB_IP] = Point3::new(0.04, -0.26, 0.0);
            p[THUMB_TIP] = Point3::new(-0.10, -0.30, 0.0);
        }
        Thumb::Up => {
            p[THUMB_MCP] = Point3::new(0.20, -0.32, 0.0);
            p[THUMB_IP] = Point3::new(0.18, -0.55, 0.0);
            p[THUMB_TIP] = Point3::new(0.16, -0.78, 0.0);
        }
        Thumb::Down => {
            p[THUMB_MCP] = Point3::new(0.22, -0.18, 0.0);
            p[THUMB_IP] = Point3::new(0.24, 0.05, 0.0);
            p[THUMB_TIP] = Point3::new(0.26, 0.30, 0.0);
        }
    }

    // Four fingers.
    let chains: [(usize, f32); 4] = [(INDEX_MCP, -1.0), (9, -0.35), (13, 0.35), (PINKY_MCP, 1.0)];
    for (slot, (mcp, lateral)) in chains.iter().enumerate() {
        let (pip, dip, tip) = (mcp + 1, mcp + 2, mcp + 3);
        if spec.extended[slot + 1] {
            let dx = lateral * spec.splay;
            let dir = Point3::new(dx, -1.0, 0.0);
            let len = dir.distance_2d(Point3::default()).max(1e-4);
            let unit = Point3::new(dir.x / len, dir.y / len, 0.0);
            p[pip] = add(p[*mcp], mul(unit, 0.26));
            p[dip] = add(p[pip], mul(unit, 0.17));
            p[tip] = add(p[dip], mul(unit, 0.13));
        } else {
            p[pip] = add(p[*mcp], Point3::new(0.0, -0.22, 0.0));
            p[dip] = add(p[pip], Point3::new(0.05, 0.13, 0.0));
            p[tip] = add(p[dip], Point3::new(0.03, 0.10, 0.0));
        }
    }

    p[WRIST] = Point3::new(0.0, 0.0, 0.0);
    p
}

fn add(a: Point3, b: Point3) -> Point3 {
    Point3::new(a.x + b.x, a.y + b.y, a.z + b.z)
}

fn mul(a: Point3, k: f32) -> Point3 {
    Point3::new(a.x * k, a.y * k, a.z * k)
}
