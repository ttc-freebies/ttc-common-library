const { readFile, writeFile, rm } = require('fs').promises;
const { readdirSync, existsSync, fstat } = require('fs');
const admZip = require('adm-zip');
const { version } = require('./package.json');

const getCurrentXml = async (path, name) => {
  let xml;
  if (existsSync(`${path !== '' ? path + '/' : ''}${name}.xml`)) {
    xml = await readFile(`${path !== '' ? path + '/' : ''}${name}.xml`, {
      encoding: 'utf8',
    });

    return xml.replace(/{{version}}/g, version);
  }
}

const zipExtension = async (path, name, type) => {
  const noRoot = path.replace(`${process.cwd()}/`, '');
  xml = await getCurrentXml(path, name);
  const zip = new admZip();

  readdirSync(path, { withFileTypes: true })
  .filter(item => !/(^|\/)\.[^/.]/g.test(item.name))
  .forEach(file => {
    if (file.isDirectory()) {
      if (file.name === 'Templates') return;
      zip.addLocalFolder(`${noRoot}/${file.name}`, file.name, /^(?!\.DS_Store)/);
    } else if (file.name === `${name.toLowerCase()}.xml`) {
      zip.addFile(file.name, xml);
    } else if (!['composer.json', 'composer.lock', '.DS_Store'].includes(file.name)) {
      zip.addLocalFile(`${noRoot}/${file.name}`, false);
    }
  });

  zip.addLocalFolder(`src_vendor/vendor/intervention/image/src/Intervention/Image`, 'src/Freebies/Intervention/Image', /^(?!\.DS_Store)/);

  zip.getEntries().forEach(entry => {
    if (/^\.DS_Store/.test(entry.entryName)) {
      zip.deleteFile(entry.entryName);
    }
  });

  writeFile(`public/dist/lib_ttc_${version}.zip`, zip.toBuffer());
}

(async function exec() {
  await zipExtension(`${process.cwd()}/src/libraries/Ttc`, `Ttc`, 'libraries');
})();
