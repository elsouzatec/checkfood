# Folder PagFood (tri-fold A4, impressão em gráfica)

Cópia dos arquivos-fonte do folder. A versão editável e o export em PDF ficam no canvas:
https://claude.ai/artifact/MHkghdQQDpGNKsrfbRWf3K (privado; compartilhar pelo menu Share).

| Arquivo | O que é |
|---|---|
| `Main.dc.html` | Face externa: aba (97 mm) · verso com contato e QR · capa |
| `Interno.dc.html` | Face interna: o problema · como funciona · o que já está pronto (97 mm) |
| `canvas.json` | Índice do canvas (tamanho das pranchas e nota para a gráfica) |
| `qr-produto.svg` | QR code para https://pagfood.online/produto |

Os `.dc.html` só renderizam dentro do canvas: dependem do `support.js` dele e
referenciam o QR code pelo upload do canvas (`/_blob/b82637d05f7bd7b833c6e1050c464d28`),
que é o mesmo conteúdo de `qr-produto.svg`.

## Especificações para a gráfica

- Formato final: A4 deitado, 297 × 210 mm, 3 dobras tipo carta (enrolado)
- Arquivo com sangria de 3 mm: 303 × 216 mm, sem marcas de corte
- Página 1 = face externa · página 2 = face interna (frente e verso, virar pela borda curta)
- Painéis da esquerda para a direita: externa 97 · 100 · 100 mm; interna 100 · 100 · 97 mm
- Dobras na face interna a 100 mm e 200 mm da borda esquerda (sem contar a sangria)
- Papel sugerido: couché 150 g, brilho ou fosco
- Cores em RGB: pedir prova de cor (conversão para CMYK)

## Conteúdo

Só funcionalidades que já rodam em produção (textos da vitrine `frontend/site`).
Contato impresso: WhatsApp (91) 98421-1468. Sem preço.
Ao lançar NFC-e, InfinitePay ou Banco Inter, atualizar o painel "Já está pronto".
