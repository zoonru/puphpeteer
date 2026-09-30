// Keep the npm package untouched. If its internal CRC API changes, retain its implementation.
export function adaptAdmZip(filename, exports, builtins) {
  if (!/(^|\/)node_modules\/adm-zip\/util\/utils\.js$/.test(filename)) return;
  const property = Object.getOwnPropertyDescriptor(exports, 'crc32');
  if (typeof property?.value !== 'function' || !property.writable) return;
  const probe = builtins.buffer.Buffer.from([0, 1, 255]);
  if (exports.crc32(probe) !== builtins.zlib.crc32(probe)) return;
  exports.crc32 = builtins.zlib.crc32;
}
