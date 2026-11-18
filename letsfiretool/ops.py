"""Operation tables — converted from ops.h/cpp"""
from __future__ import annotations
from dataclasses import dataclass
from enum import IntEnum
from typing import List


@dataclass(frozen=True)
class DaItem:
    name: str
    file: str


DA_ITEMS: List[DaItem] = [
    DaItem("MT6835 (DA-0)", "da_6835.bin"),
    DaItem("MT6835 (DA-1)", "da_6835_1og.bin"),
    DaItem("MT6835 (DA-2)", "da_6835_2.bin"),
    DaItem("MT6789 (DA-2)", "da_6789_fire.bin"),
    DaItem("MT6878 (DA-1)", "da_6878.bin"),
    DaItem("MT6878 (DA-2)", "da_6878_new2.bin"),
    DaItem("MT6897 (DA-1)", "da_6897_fire.bin"),
    DaItem("MT6991 (DA-1)", "da_6991.bin"),
]


def da_items() -> List[DaItem]:
    return list(DA_ITEMS)


OPERATIONS = [
    "Factory Reset (FRP+Userdata)",
    "Erase FRP Only",
    "Format Userdata Only",
    "Erase FRP + Metadata",
    "Unlock Bootloader",
]


class TaskKind(IntEnum):
    EraseFlash = 0
    ErasePartition = 1
    UnlockBootloader = 2
    Reboot = 3


@dataclass(frozen=True)
class Task:
    kind: TaskKind
    name: str
    label: str


FRP = Task(TaskKind.EraseFlash, "frp", "Erase FRP")
METADATA = Task(TaskKind.EraseFlash, "metadata", "Erase Metadata")
USERDATA = Task(TaskKind.ErasePartition, "userdata", "Format Userdata")
UNLOCK = Task(TaskKind.UnlockBootloader, "", "Unlock Bootloader")
REBOOT = Task(TaskKind.Reboot, "", "Reboot")


def tasks_for(op: int) -> List[Task]:
    if op == 0:
        return [FRP, METADATA, USERDATA, REBOOT]
    if op == 1:
        return [FRP, REBOOT]
    if op == 2:
        return [USERDATA, REBOOT]
    if op == 3:
        return [FRP, METADATA, REBOOT]
    if op == 4:
        return [UNLOCK, REBOOT]
    return []
