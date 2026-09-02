=== TK Media Optimizer ===
Contributors: tikovolpestudio
Tags: webp, images, media, optimization, performance
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Converte automaticamente imagens PNG/JPG para WebP no upload, mantendo o original intacto.

== Description ==

TK Media Optimizer converte automaticamente toda imagem PNG, JPG ou JPEG enviada
para a biblioteca de mídia do WordPress em uma cópia WebP, salva na mesma pasta
do arquivo original.

= Como funciona =

* Ao fazer upload de uma imagem, o plugin gera um arquivo `.webp` equivalente
  na mesma pasta do original (ex: `foto.jpg` -> `foto.webp`).
* Usa a extensão GD como conversor principal e Imagick como fallback.
* Se nenhuma das duas extensões estiver disponível, o plugin não faz nada e o
  upload segue normalmente, sem erros.
* O caminho e a URL do arquivo WebP são salvos como metadados da mídia
  original (`_tk_webp_url` e `_tk_webp_path`), prontos para uso em outros
  plugins ou temas.
* Ao excluir a mídia original, o arquivo `.webp` correspondente é removido
  junto.

= Compatibilidade =

* PHP 7.4 ou superior
* WordPress 5.8 ou superior
* Funciona em hospedagem compartilhada (Hostgator, Locaweb, Kinghost) sem
  necessidade de configuração especial de servidor.
* Nenhuma dependência externa: usa apenas GD ou Imagick, nativos do PHP.

== Installation ==

1. Envie a pasta `tk-media-optimizer` para `/wp-content/plugins/`.
2. Ative o plugin no menu "Plugins" do WordPress.
3. Pronto. Novos uploads de imagem serão convertidos automaticamente.

== Frequently Asked Questions ==

= O arquivo original é substituído? =

Não. O original é mantido intacto; o `.webp` é criado como um arquivo adicional
na mesma pasta.

= O que acontece se o servidor não tiver GD nem Imagick? =

O plugin detecta a ausência de ambas as extensões e não interfere no upload —
a imagem original é enviada normalmente, sem conversão.

= Qual a qualidade usada na conversão? =

82, aplicada a todas as conversões.

== Changelog ==

= 1.0.9 =
* Entrega da LCP: links `<link rel="preload" as="image">` do tema passam a
  apontar para o `.webp` quando ele existe, alinhando o preload inicial com
  a imagem que o `<picture>` realmente serve (antes o navegador baixava o
  JPG/PNG do preload sem usar). `imagesrcset` responsivo só é reescrito
  quando todos os candidatos têm `.webp`, preservando a seleção do
  navegador. Nenhuma mudança em `<img>`/`<picture>` já tratados.

= 1.0.8 =
* Nova seção "Detalhes dos erros" no dashboard: lista cada arquivo que
  falhou na conversão com o motivo exato (imagem grande demais e suas
  dimensões, GD/Imagick não abriu, pasta sem escrita, exceção do PHP, etc.).
* O motivo da falha agora é persistido por arquivo, não só um contador.

= 1.0.7 =
* Correção: batch de conversão deixava de causar erro 500 em VPS. Imagens
  muito grandes (acima de 24 megapixels por padrão) são puladas e marcadas
  como erro em vez de esgotar a memória do PHP.
* Batch agora eleva memory_limit (wp_raise_memory_limit), trata subpastas
  sem permissão de leitura sem abortar, e não refaz a varredura completa do
  disco a cada tick (só ao final).
* BATCH_SIZE reduzido para 3, pausa entre ticks e trava contra requisições
  sobrepostas. Falha de conversão agora retorna mensagem clara e a fila
  continua do próximo arquivo.

= 1.0.0 =
* Versão inicial.
