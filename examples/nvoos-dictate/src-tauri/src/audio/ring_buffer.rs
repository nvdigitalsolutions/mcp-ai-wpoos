//! Lock-free-ish pre-roll ring buffer for microphone samples.
//!
//! The mic stream stays open while the app runs; samples land in this ring
//! buffer and are never written to disk (privacy default). On PTT press the
//! dictation snapshot starts `pre_roll` samples *before* the press so the
//! first syllable is never cut — the murmur pre-roll technique.

use std::collections::VecDeque;

pub struct RingBuffer {
    buf: VecDeque<f32>,
    capacity: usize,
}

impl RingBuffer {
    pub fn new(capacity: usize) -> Self {
        Self {
            buf: VecDeque::with_capacity(capacity.min(1 << 20)),
            capacity: capacity.min(1 << 20),
        }
    }

    pub fn push(&mut self, samples: &[f32]) {
        for &s in samples {
            if self.buf.len() == self.capacity {
                self.buf.pop_front();
            }
            self.buf.push_back(s);
        }
    }

    pub fn len(&self) -> usize {
        self.buf.len()
    }

    pub fn is_empty(&self) -> bool {
        self.buf.is_empty()
    }

    /// Copy the last `n` samples (fewer if the buffer hasn't filled yet).
    pub fn snapshot_last(&self, n: usize) -> Vec<f32> {
        let start = self.buf.len().saturating_sub(n);
        self.buf.iter().skip(start).copied().collect()
    }

    /// Copy samples between the given absolute positions, where positions
    /// advance monotonically with `push` calls (callers track an index).
    pub fn snapshot_range(&self, from: usize, to: usize) -> Vec<f32> {
        let len = self.buf.len();
        let start = from.min(len);
        let end = to.min(len);
        if start >= end {
            return Vec::new();
        }
        self.buf
            .iter()
            .skip(start)
            .take(end - start)
            .copied()
            .collect()
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn wraps_and_snapshots() {
        let mut rb = RingBuffer::new(4);
        rb.push(&[1.0, 2.0, 3.0]);
        assert_eq!(rb.snapshot_last(10), vec![1.0, 2.0, 3.0]);
        rb.push(&[4.0, 5.0]); // wraps: drops 1.0
        assert_eq!(rb.snapshot_last(10), vec![2.0, 3.0, 4.0, 5.0]);
        assert_eq!(rb.snapshot_last(2), vec![4.0, 5.0]);
    }

    #[test]
    fn snapshot_range_bounds() {
        let mut rb = RingBuffer::new(8);
        rb.push(&[1.0, 2.0, 3.0, 4.0, 5.0]);
        assert_eq!(rb.snapshot_range(1, 4), vec![2.0, 3.0, 4.0]);
        assert_eq!(rb.snapshot_range(10, 20), Vec::<f32>::new());
    }
}
