const fs = require('node:fs');
const path = require('node:path');

module.exports = () => ({
  name: 'quickjs-protocol-stream',
  setup(build) {
    build.onLoad({filter: /[\\/]puppeteer[\\/]common[\\/]util\.js$/}, args => {
      const source = fs.readFileSync(args.path, 'utf8');
      const pattern = /^export async function getReadableFromProtocolStream\(client, handle\) \{.*?^\}/ms;
      const match = source.match(pattern);
      if (!match || !match[0].includes("client.send('IO.read'") || /\bcancel\s*\(/.test(match[0])) {
        throw new Error('Upstream protocol stream changed; review the QuickJS cancellation adapter');
      }
      return {contents: source.replace(pattern, `export {getReadableFromProtocolStream} from ${JSON.stringify(path.resolve(__dirname, '../js/protocol-stream.js'))};`), loader: 'js'};
    });
  },
});
