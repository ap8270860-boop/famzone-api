#!/usr/bin/env python3
"""Import the group request class in the controller.

createGroup type-hints CreateGroupRequest but nothing imported it, so PHP
looked for it in the controller's own namespace and the route 500'd.

Run from the famzone-api repo root. Idempotent and guarded.
"""

import io
import sys

path = 'app/Http/Controllers/Api/V1/V1Controller.php'

s = io.open(path, encoding='utf-8').read()

if 'Chat\\CreateGroupRequest;' in s:
    print(f'{path}: already patched')
    raise SystemExit(0)

anchor = "use App\\Http\\Requests\\Api\\V1\\Chat\\ForwardRequest;"

if anchor not in s:
    sys.exit(f'{path}: anchor missing -> {anchor}')

s = s.replace(
    anchor,
    "use App\\Http\\Requests\\Api\\V1\\Chat\\CreateGroupRequest;\n" + anchor,
    1,
)

io.open(path, 'w', encoding='utf-8', newline='').write(s)

print(f'{path}: patched')
