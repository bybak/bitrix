from __future__ import annotations

import sys
import threading
import time
from dataclasses import dataclass, field
from typing import TextIO


def _format_duration(seconds: float) -> str:
    seconds = max(0, int(seconds))
    minutes, sec = divmod(seconds, 60)
    hours, minutes = divmod(minutes, 60)
    if hours:
        return f"{hours:02d}:{minutes:02d}:{sec:02d}"
    return f"{minutes:02d}:{sec:02d}"


@dataclass
class ProgressReporter:
    total: int
    label: str = "import"
    stream: TextIO = sys.stdout
    min_interval_sec: float = 15.0
    started_at: float = field(default_factory=time.monotonic)
    _last_emit_at: float = field(default=0.0, init=False, repr=False)
    _lock: threading.Lock | None = field(default=None, init=False, repr=False)

    def enable_thread_safe(self) -> None:
        if self._lock is None:
            self._lock = threading.Lock()

    def __post_init__(self) -> None:
        self.total = max(int(self.total), 1)
        self.done = 0
        self.stage = ""
        self.stage_total = 1
        self.stage_done = 0
        self._emit("started", force=True)

    def set_stage(self, stage: str, total: int) -> None:
        if self._lock:
            with self._lock:
                self._set_stage_unlocked(stage, total)
        else:
            self._set_stage_unlocked(stage, total)

    def _set_stage_unlocked(self, stage: str, total: int) -> None:
        self.stage = stage
        self.stage_total = max(int(total), 1)
        self.stage_done = 0
        self._emit("stage started", force=True)

    def add_total(self, amount: int) -> None:
        if self._lock:
            with self._lock:
                self.total = max(1, self.total + int(amount))
                self._emit(f"discovered {amount} more items")
        else:
            self.total = max(1, self.total + int(amount))
            self._emit(f"discovered {amount} more items")

    def advance(self, message: str = "", step: int = 1) -> None:
        if self._lock:
            with self._lock:
                self._advance_unlocked(message, step=step)
        else:
            self._advance_unlocked(message, step=step)

    def _advance_unlocked(self, message: str = "", *, step: int = 1) -> None:
        self.done = min(self.total, self.done + step)
        self.stage_done = min(self.stage_total, self.stage_done + step)
        force = bool(message.startswith("ERROR")) or message.endswith("stage started")
        self._emit(message or "progress", force=force)

    def finish(self, message: str = "finished") -> None:
        if self._lock:
            with self._lock:
                self.done = self.total
                self.stage_done = self.stage_total
                self._emit(message, force=True)
        else:
            self.done = self.total
            self.stage_done = self.stage_total
            self._emit(message, force=True)

    def _emit(self, message: str, *, force: bool = False) -> None:
        now = time.monotonic()
        if not force and (now - self._last_emit_at) < self.min_interval_sec:
            return
        self._last_emit_at = now
        elapsed = now - self.started_at
        overall_percent = self.done / self.total * 100
        stage_percent = self.stage_done / self.stage_total * 100
        rate = self.done / elapsed if elapsed > 0 else 0
        remaining = (self.total - self.done) / rate if rate > 0 else 0
        print(
            (
                f"[{self.label}] {message} | "
                f"stage={self.stage or '-'} {self.stage_done}/{self.stage_total} ({stage_percent:5.1f}%) | "
                f"overall={self.done}/{self.total} ({overall_percent:5.1f}%) | "
                f"elapsed={_format_duration(elapsed)} eta={_format_duration(remaining)}"
            ),
            file=self.stream,
            flush=True,
        )
