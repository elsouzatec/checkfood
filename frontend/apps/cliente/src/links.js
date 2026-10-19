// Links do PagFood — inclusive os lidos de QR Code: mesa (/mesa/{token}) e
// link do restaurante (/r/{slug}). parseScannedUrl aceita a URL completa
// (qualquer domínio/IP — intranet incluída) ou só o caminho.
export function parseScannedUrl(raw) {
  var path
  try {
    path = new URL(raw).pathname
  } catch (e) {
    path = String(raw || '')
  }

  var mesa = path.match(/\/mesa\/([a-f0-9-]+)/i)
  if (mesa) return { type: 'table', token: mesa[1], path: '/mesa/' + mesa[1] }

  var restaurant = path.match(/^\/r\/([a-z0-9-]+)\/?$/i)
  if (restaurant) return { type: 'restaurant', slug: restaurant[1], path: '/r/' + restaurant[1] }

  return null
}

// logo_url pode vir absoluta ou relativa ao domínio (/storage/...).
export function logoSrc(url) {
  if (!url) return ''
  return url.indexOf('http') === 0 ? url : window.location.origin + url
}
