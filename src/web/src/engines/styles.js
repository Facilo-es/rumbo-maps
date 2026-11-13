// Style names shared by every provider: liberty, bright, positron (default), dark, fiord.
const OFM = /^https:\/\/tiles\.openfreemap\.org\/styles\/(\w+)/;

/** 'positron' or an OpenFreeMap URL -> 'positron'. A custom style URL -> ''. */
export function styleName(style) {
  const s = String(style || 'positron').trim();
  const m = s.match(OFM);
  if (m) return m[1];
  return /^[a-z-]+$/i.test(s) ? s.toLowerCase() : '';
}

/** A provider's own style URL (https://, mapbox://) that is not an OpenFreeMap one, else ''. */
export function customStyle(style) {
  const s = String(style || '').trim();
  return /^(https|mapbox):\/\//i.test(s) && !OFM.test(s) ? s : '';
}
