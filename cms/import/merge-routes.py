#!/usr/bin/env python3
"""Merge cms/import/routes/*.json into block-map.json — refusing, never guessing.

    python3 cms/import/merge-routes.py            # merge and write
    python3 cms/import/merge-routes.py --check    # dry run, report only

WHY THIS EXISTS. Migration waves run one agent per page, and the wave before
this tool existed had seven agents editing block-map.json concurrently — a
real clobbering risk that cost review time to rule out. Each agent now writes
its route to its own file under routes/, and this folds them in afterwards.

WHY IT REFUSES RATHER THAN GUESSES. The one unacceptable outcome is a merge
that quietly edits a route that is already live. So it refuses on: a file
with no "route" key, two files claiming one route, a route the map already
has, and — the one that matters — any merge whose re-serialisation changes an
existing route byte-for-byte. Merged files are deleted on success so a rerun
cannot double-add them.
"""
import json, collections, glob, os, sys

ROOT = os.path.dirname(os.path.abspath(__file__))
MAP = os.path.join(ROOT, "block-map.json")
CHECK = "--check" in sys.argv

d = json.load(open(MAP), object_pairs_hook=collections.OrderedDict)
before = {k: json.dumps(v, sort_keys=True) for k, v in d["routes"].items()}

files = sorted(glob.glob(os.path.join(ROOT, "routes", "*.json")))
if not files:
    sys.exit("nothing to merge: cms/import/routes/ is empty")

seen, added = {}, []
for f in files:
    entry = json.load(open(f), object_pairs_hook=collections.OrderedDict)
    route = entry.pop("route", None)
    base = os.path.basename(f)
    if not route:
        sys.exit(f'REFUSED: {base} has no "route" key')
    if route in seen:
        sys.exit(f"REFUSED: {base} and {seen[route]} both claim {route}")
    if route in d["routes"]:
        sys.exit(f"REFUSED: {route} is already in block-map.json ({base})")
    seen[route] = base
    d["routes"][route] = entry
    added.append((route, len(entry.get("blocks", []))))

for k, v in before.items():
    if json.dumps(d["routes"][k], sort_keys=True) != v:
        sys.exit(f"REFUSED: merging changed the existing route {k}")

if CHECK:
    print(f"--check: {len(added)} route(s) would merge cleanly; "
          f"{len(before)} existing route(s) untouched")
else:
    json.dump(d, open(MAP, "w"), indent=1, ensure_ascii=False)
    open(MAP, "a").write("\n")
    for f in files:
        os.remove(f)
    print(f"{len(added)} route(s) merged; {len(before)} existing byte-identical; "
          f"route files consumed")

for r, n in added:
    print(f"   {r:<48} {n} block(s)")
print(f"block-map.json describes {len(d['routes']) if not CHECK else len(before) + len(added)} routes")
