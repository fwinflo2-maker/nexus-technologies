import { readdir, stat } from 'node:fs/promises';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const assetsDirectory = fileURLToPath(new URL('../dist/assets/', import.meta.url));
const maxChunkBytes = 650 * 1024;
const files = await readdir(assetsDirectory);
const chunks = [];

for (const file of files.filter((name) => name.endsWith('.js'))) {
  const size = (await stat(join(assetsDirectory, file))).size;
  chunks.push({ file, size });
}

chunks.sort((a, b) => b.size - a.size);
for (const chunk of chunks) {
  console.log(`${chunk.file}: ${(chunk.size / 1024).toFixed(1)} KiB`);
}

const oversized = chunks.filter((chunk) => chunk.size > maxChunkBytes);
if (oversized.length > 0) {
  console.error(`Bundle budget exceeded: JavaScript chunks must stay below ${maxChunkBytes / 1024} KiB.`);
  process.exitCode = 1;
}
