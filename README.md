<p align="center">
  <img src="docs/hero.svg" alt="Pagou para WooCommerce. Pix, Boleto, Cartão de Crédito e Split." width="100%">
</p>

<p align="center">
  <strong>Pagamentos integrados. Confirmação automática. Código aberto.</strong><br>
  O plugin oficial da Pagou para receber na sua loja WooCommerce.
</p>

<p align="center">
  <a href="https://github.com/pagou-com-br/woocommerce/releases/tag/v2.0.1"><img src="https://img.shields.io/badge/vers%C3%A3o-2.0.1-0071df?style=flat-square" alt="Versão 2.0.1"></a>
  <a href="#requisitos"><img src="https://img.shields.io/badge/WordPress-6.6%2B-697ab1?style=flat-square&amp;logo=wordpress&amp;logoColor=white" alt="WordPress 6.6 ou superior"></a>
  <a href="#requisitos"><img src="https://img.shields.io/badge/WooCommerce-9.0%2B-697ab1?style=flat-square&amp;logo=woocommerce&amp;logoColor=white" alt="WooCommerce 9.0 ou superior"></a>
  <a href="#requisitos"><img src="https://img.shields.io/badge/PHP-7.4%2B-697ab1?style=flat-square&amp;logo=php&amp;logoColor=white" alt="PHP 7.4 ou superior"></a>
  <img src="https://img.shields.io/badge/idioma-Portugu%C3%AAs%20BR-15836b?style=flat-square" alt="Interface em português do Brasil">
  <a href="https://wordpress.org/plugins/pagou-payments-for-woocommerce/"><img src="https://img.shields.io/badge/WordPress.org-publicado-16866a?style=flat-square" alt="Publicado no WordPress.org"></a>
  <a href="#licenca"><img src="https://img.shields.io/badge/licen%C3%A7a-GPLv2%2B-526475?style=flat-square" alt="Licença GPLv2 ou posterior"></a>
</p>

<p align="center">
  <a href="https://wordpress.org/plugins/pagou-payments-for-woocommerce/"><strong>Instalar pelo WordPress.org</strong></a>
  &nbsp;·&nbsp;
  <a href="https://github.com/pagou-com-br/woocommerce/releases/download/v2.0.1/pagou-payments-for-woocommerce-2.0.1.zip"><strong>Baixar o plugin (.zip)</strong></a>
  &nbsp;·&nbsp;
  <a href="https://github.com/pagou-com-br/woocommerce/releases/tag/v2.0.1">Release notes</a>
  &nbsp;·&nbsp;
  <a href="https://github.com/pagou-com-br/woocommerce/releases/download/v2.0.1/SHA256SUMS">Checksum SHA-256</a>
</p>

<p align="center">
  <a href="#requisitos">Requisitos</a> ·
  <a href="#instalacao">Instalação</a> ·
  <a href="#configuracao">Configuração</a> ·
  <a href="#estrutura">Estrutura</a> ·
  <a href="#ganchos">Ganchos</a> ·
  <a href="#webhook">Webhook</a> ·
  <a href="#licenca">Licença</a>
</p>

Plugin oficial da [Pagou](https://pagou.com.br/) para WooCommerce: Pix, boleto e cartão de crédito, com parcelamento, cartão salvo e split de pagamento entre contas Pagou.

<a id="requisitos"></a>

## Requisitos

- WordPress 6.6 ou superior, PHP 7.4 ou superior
- WooCommerce 9.0 ou superior
- Conta Pagou com chave de API (cartão e split precisam ser habilitados pela Pagou)

<a id="instalacao"></a>

## Instalação

- **Pelo WordPress (recomendado):** em **Plugins > Adicionar novo**, busque **Pagou** e clique em **Instalar agora**. As atualizações chegam pelo próprio painel.
- **Pelo .zip:** [baixe o plugin](https://github.com/pagou-com-br/woocommerce/releases/download/v2.0.1/pagou-payments-for-woocommerce-2.0.1.zip) e envie em **Plugins > Adicionar novo > Enviar plugin**. É o mesmo pacote publicado no WordPress.org.

<a id="configuracao"></a>

## Configuração

1. Ative o plugin.
2. Em **WooCommerce > Configurações > Pagou**, escolha o ambiente (produção ou sandbox), informe a chave de API e clique em **Testar conexão**. A chave também pode ser definida pela constante `PAGOU_API_KEY` no `wp-config.php`.
3. Ative Pix, boleto e cartão em **WooCommerce > Configurações > Pagamentos**.
4. Opcional: configure o split na mesma aba da Pagou e, por produto, na aba **Split Pagou**.

<a id="estrutura"></a>

## Estrutura

- `includes/classes/`: código PSR-4 no namespace `Pagou\WooCommerce` (gateways, API, webhook, conciliação, split, checkout em blocos).
- `templates/`: telas de pagamento, que o tema pode sobrescrever em `woocommerce/pagou/`.
- `assets/`: scripts do checkout, tokenização do cartão e estilos.

<a id="ganchos"></a>

## Ganchos

- `pagou_wc_corpo_cobranca` (filtro): altera o corpo enviado à Pagou ao criar uma cobrança.
- `pagou_wc_split_recusado` (ação): disparada quando a Pagou recusa o split de uma cobrança.
- `pagou_wc_campos_configuracao` (filtro): acrescenta seções à aba de configurações.

<a id="webhook"></a>

## Webhook

As notificações chegam em `/wp-json/pagou/v1/webhook` (ou `?rest_route=/pagou/v1/webhook` sem permalinks amigáveis), assinadas com HMAC-SHA256. A URL é enviada em cada cobrança; não é preciso cadastrá-la no painel da Pagou.

<a id="licenca"></a>

## Licença

GPLv2 ou posterior.
