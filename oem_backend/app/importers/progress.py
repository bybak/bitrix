from __future__ import annotations

import sys
import time
from dataclasses import dataclass
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
    stream: TextIO = sys.stderr

    def __post_init__(self) -> None:
        self.total = max(int(self.total), 1)
        self.done = 0
        self.stage = ""
        self.stage_total = 1
        self.stage_done = 0
        self.started_at = time.monotonic()
        self._emit("started")

    def set_stage(self, stage: str, total: int) -> None:
        self.stage = stage
        self.stage_total = max(int(total), 1)
        self.stage_done = 0
        self._emit("stage started")

    def add_total(self, amount: int) -> None:
        self.total = max(1, self.total + int(amount))
        self._emit(f"discovered {amount} more items")

    def advance(self, message: str = "", step: int = 1) -> None:
        self.done = min(self.total, self.done + step)
        self.stage_done = min(self.stage_total, self.stage_done + step)
        self._emit(message or "progress")

    def finish(self, message: str = "finished") -> None:
        self.done = self.total
        self.stage_done = self.stage_total
        self._emit(message)

    def _emit(self, message: str) -> None:
        elapsed = time.monotonic() - self.started_at
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
