#!/usr/bin/env python3
"""Drop the dead is_online column's cast from the model."""

import io
import sys

P = 'app/Models/User.php'
s = io.open(P, encoding='utf-8').read()

if "'is_online'" not in s:
    sys.exit(f'{P}: already clean')

OLD = "            'is_online' => 'boolean',\n"

if OLD not in s:
    sys.exit(f'{P}: is_online cast not found in the expected shape')

io.open(P, 'w', encoding='utf-8', newline='').write(s.replace(OLD, '', 1))
print(f'{P}: is_online cast removed')
