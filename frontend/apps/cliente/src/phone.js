// Máscara de celular brasileiro enquanto digita: (84) 99999-9999
export function maskPhone(value) {
  var v = String(value || '').replace(/\D/g, '').slice(0, 11)
  if (v.length > 6) return '(' + v.slice(0, 2) + ') ' + v.slice(2, 7) + '-' + v.slice(7)
  if (v.length > 2) return '(' + v.slice(0, 2) + ') ' + v.slice(2)
  if (v.length > 0) return '(' + v
  return v
}

export function isValidPhone(value) {
  return String(value || '').replace(/\D/g, '').length >= 10
}
