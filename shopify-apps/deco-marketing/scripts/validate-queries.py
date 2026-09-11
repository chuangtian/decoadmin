"""Offline validation using the installed Shopify skill schema. No telemetry/network."""
import base64
import os
from pathlib import Path
import re
import subprocess
import sys

root = Path(__file__).resolve().parents[1]
source = (root / 'backend/Services/Shopify.php').read_text()
fields = re.search(r"private const ORDER_FIELDS = '([^']+)'", source).group(1)
source = source.replace("'.self::ORDER_FIELDS.'", fields)
queries = re.findall(r"'(query [^']+)'", source)
queries += re.findall(r"'(query [^']+)'", (root / 'backend/Services/Tokens.php').read_text())
node = os.environ.get('NODE_BINARY', 'node')
skill = Path(os.environ.get('SHOPIFY_ADMIN_SKILL', str(Path.home() / '.agents/skills/shopify-admin')))
result = subprocess.run([node, str(skill / 'scripts/validate.mjs'), '--code', '\n'.join(queries),
    '--model', 'GPT', '--client-name', 'Codex', '--client-version', 'desktop',
    '--artifact-id', 'deco-marketing-queries', '--revision', '4', '--version', '2026-07',
    '--user-prompt-base64', base64.b64encode('Macfox Bike 这个只给你查看，你不要操作它'.encode()).decode()],
    env={**os.environ, 'OPT_OUT_INSTRUMENTATION': 'true'})
sys.exit(result.returncode)
