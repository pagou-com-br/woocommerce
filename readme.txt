=== Pagou - Payments for WooCommerce ===
Contributors: pagoupagamentos, alissonic
Donate link: https://pagou.com.br/
Tags: woocommerce, pix, boleto, credit card, split payment
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Accept Pix, boleto and credit card in WooCommerce through Pagou, with installments, saved cards and payment split.

== Description ==

Pagou for WooCommerce connects your store to the Pagou payment gateway and adds the most used payment methods in Brazil: Pix (instant bank transfer), boleto bancário (bank slip) and credit card.

**Payment methods**

* Pix with automatic confirmation: the customer pays and the order is updated on its own.
* Boleto bancário with barcode, digitable line, PDF and an embedded Pix code, including optional late fee and interest.
* Credit card with installments. The store owner sets the maximum number of installments, the minimum installment amount, how many installments are interest free and the monthly interest rate (Price table). Interest is shown on the order as a separate fee.
* Saved cards: returning customers can pay with a card saved in My Account. Only a token issued by Pagou is stored; card data never reaches your server.

**Payment split**

* Split each sale between your Pagou account and other Pagou accounts (sellers, partners, affiliates).
* Global rules for the whole store, plus a rule per product (use the global rule, a specific rule or no split).
* Percentage or fixed amount, calculated over the products or over the whole order.
* If Pagou rejects the split (for example, a recipient was not authorized), the sale is not lost: the charge is created without split and the store owner is warned in the dashboard and on the order.

**Checkout and operation**

* Compatible with the classic checkout, the Cart and Checkout Blocks and HPOS (High-Performance Order Storage).
* Brazilian checkout fields (CPF/CNPJ, number and neighborhood) are added automatically when no other plugin provides them. Brazilian Market on WooCommerce is optional.
* Discount per payment method (percentage or fixed amount), for example 5% off with Pix.
* Payment screen with the amount in focus, expiration countdown, one-click copy and automatic update when the payment is confirmed.
* Pix code and boleto digitable line included in the order emails.
* Signed webhooks (HMAC-SHA256) with replay protection, amount verification and idempotency, received by a REST API route.
* Automatic reconciliation every 15 minutes: if a notification does not reach the store, the plugin queries Pagou and updates the order anyway.
* Card protections: idempotent charges, limit of declined attempts per order and per IP, and chargeback monitoring.
* Full refunds from the order screen (Pix and card).

**Requirements**

A Pagou account and API key, and WooCommerce 9.0 or later. Credit card and split must be enabled for your account by Pagou.

**Em português**

O plugin integra o WooCommerce à Pagou para receber por Pix, boleto e cartão de crédito, com parcelamento configurável, cartão salvo, split de pagamento entre contas Pagou, desconto por meio de pagamento e confirmação automática. Funciona no checkout clássico e no checkout em blocos, e o Brazilian Market on WooCommerce passou a ser opcional.

== External services ==

This plugin connects to the following external services. No data is sent before the store owner configures an API key and a customer chooses a Pagou payment method.

1. **Pagou API** (https://api.pagou.com.br in production, https://api.pagou.cloud in sandbox)
   - **What it is used for:** creating, querying, capturing and refunding Pix, boleto and credit card charges, applying payment split and checking the account connection.
   - **Data sent:** order amount, order number, customer name, email, CPF/CNPJ, phone and billing address, customer IP address (required by the card antifraud), the card token generated in the browser, installments and split recipients.
   - **When:** at checkout, when an order is refunded or captured, every 15 minutes for pending orders (reconciliation), and when the store owner clicks "Test connection".
   - [Terms of use](https://pagou.com.br/termos) and [Privacy policy](https://pagou.com.br/privacidade).

2. **Pagou card tokenization script** (https://js.cel.cash/checkout-v2.min.js)
   - **What it is used for:** encrypting the card number, holder name, expiration date and security code in the customer's browser and exchanging them for a single use token. Card data never passes through the store server.
   - **Data sent:** the card data typed by the customer, sent directly from the browser to the Pagou tokenization service.
   - **When:** only on the checkout and My Account > Payment methods pages, when the credit card method is enabled, at the moment the customer submits a card.
   - Provided by Pagou under the same [Terms of use](https://pagou.com.br/termos) and [Privacy policy](https://pagou.com.br/privacidade).

3. **Pagou boleto PDF** (https://fatura.pagou.com.br)
   - **What it is used for:** link to the boleto PDF shown on the payment screen and in emails. The customer's browser opens it only when the link is clicked.

== Installation ==

1. Install the plugin from Plugins > Add New, or upload it to `/wp-content/plugins/`.
2. Activate it in the Plugins menu.
3. Go to WooCommerce > Settings > Pagou, choose the environment and paste your API key. Click "Test connection".
4. Enable Pix, boleto and credit card in WooCommerce > Settings > Payments.
5. Optional: configure the split in WooCommerce > Settings > Pagou and, per product, in the "Split Pagou" tab.

== Frequently Asked Questions ==

= Where do I get the API key? =

In your Pagou dashboard. The same key is used for Pix, boleto and credit card. You can also define it with the `PAGOU_API_KEY` constant in `wp-config.php` to keep it out of the database.

= Do I need Brazilian Market on WooCommerce? =

No. Since 2.0.0 the plugin adds the CPF/CNPJ, number and neighborhood fields itself when they are missing. If Brazilian Market is active, the classic checkout uses its fields.

= How are card installments with interest calculated? =

You choose up to how many installments are interest free and the monthly rate charged above that. The plugin uses the Price table and adds the interest to the order as a fee, so the amount charged matches what the customer saw.

= Is card data stored in my store? =

No. The card is encrypted in the browser by the Pagou script and only a token reaches the store. Saved cards keep only the token, brand, last four digits and expiration date.

= How do I set up the payment split? =

Ask Pagou to enable split on your account and authorize each recipient in the Pagou dashboard. Then add the recipients (Pagou account ID) and their share in WooCommerce > Settings > Pagou. Each product can follow the global rule, have its own rule or have no split.

= What happens if Pagou rejects the split? =

The charge is created without split, so the customer can pay normally, and the store owner sees a warning in the dashboard and a note on the order.

= Can I make partial refunds? =

Card refunds must be full refunds, as required by the Pagou card API.

= What if the payment notification does not reach my store? =

The plugin checks pending orders with Pagou every 15 minutes and confirms the ones already paid.

= Is there a sandbox? =

Yes. Choose "Sandbox" in WooCommerce > Settings > Pagou and use the API key of your Pagou sandbox account.

== Changelog ==

= 2.0.0 =
* Novo: cartão de crédito, no checkout clássico e no checkout em blocos. O cartão é criptografado no navegador pela Pagou e só um token chega à loja.
* Novo: parcelamento configurável pelo lojista: número máximo de parcelas, valor mínimo da parcela, parcelas sem juros e juros ao mês (tabela Price). Os juros aparecem no pedido como "Juros do parcelamento".
* Novo: cartão salvo em Minha conta > Métodos de pagamento, para o cliente comprar de novo sem digitar o cartão.
* Novo: estorno integral do cartão pelo pedido e monitoramento de chargebacks, com o status do pedido configurável.
* Novo: proteções do cartão: cobrança idempotente, limite de tentativas recusadas por pedido e por IP, captura automática de cartões só autorizados e conciliação de cobranças com resposta incerta.
* Novo: split de pagamento entre contas Pagou, com regras globais e por produto, em percentual ou valor fixo, sobre os produtos ou sobre o pedido inteiro. Se a Pagou recusar o split, a venda segue sem ele e o lojista é avisado no painel e no pedido.
* Novo: aba WooCommerce > Configurações > Pagou, com uma única chave de API para todos os meios, escolha do ambiente e botão "Testar conexão". A chave pode ficar na constante PAGOU_API_KEY.
* Novo: campos de CPF/CNPJ, número e bairro próprios, no checkout em blocos e no clássico. O Brazilian Market on WooCommerce deixou de ser obrigatório.
* Novo: webhook recebido por rota da API REST do WordPress, que funciona mesmo sem permalinks amigáveis. A URL antiga continua aceita.
* Melhoria: o QR Code do Pix é desenhado no navegador, reduzindo de cerca de 29 KB para menos de 1 KB o que fica salvo em cada pedido.
* Melhoria: os templates da tela de pagamento podem ser sobrescritos pelo tema em `woocommerce/pagou/`.
* Correção: o sandbox aponta para o novo endereço da Pagou (api.pagou.cloud).
* Atualização: as configurações da 1.x são migradas automaticamente. Pedidos Pix e boleto pendentes criados na versão anterior continuam funcionando.

= 1.3.1 =
* Correção: pagamentos deixavam de ser gerados em lojas cujo checkout não preenche o campo "tipo de pessoa", com a mensagem "Falha ao validar os dados enviados: Nome Completo e Documento (CPF/CNPJ)". O documento do cliente agora é localizado em qualquer checkout e o tipo de pessoa é identificado pelo próprio CPF ou CNPJ.
* Correção: descontos em percentual terminados em zero apareciam errados no checkout (10% era exibido como 1%, 20% como 2%). O valor cobrado sempre esteve correto; o problema era apenas na exibição.
* Melhoria: se o CPF ou CNPJ estiver ausente ou inválido, o cliente recebe uma mensagem clara em vez do erro genérico devolvido pela API.

= 1.3.0 =
* Novo: desconto por meio de pagamento. Configure, por exemplo, 5% de desconto no PIX (percentual ou valor fixo) e aumente a conversão do checkout.
* Novo: o desconto aparece junto ao nome do meio de pagamento no checkout, como "PIX (5% de desconto)".
* Novo: ícones do PIX e do boleto na lista de meios de pagamento, no checkout clássico e no checkout em blocos.
* Melhoria: os meios de pagamento passam a se chamar "Pix Instantâneo" e "Boleto Bancário" por padrão, com textos de checkout mais claros. Nome e descrição continuam totalmente editáveis nas configurações de cada meio de pagamento; lojas que já personalizaram esses campos não são afetadas.
* Novo: conciliação automática a cada 15 minutos, que confirma pagamentos caso alguma notificação (webhook) não chegue à loja.
* Novo: registro de log das chamadas à API e dos webhooks em WooCommerce > Status > Logs, ativado pelo "Modo Debug".
* Novo: tela de pagamento redesenhada. O pagamento aparece antes dos detalhes do pedido, com valor em destaque, contagem de expiração do código, botão de copiar com confirmação e passo a passo.
* Novo: a tela identifica o pagamento sozinha e se atualiza, sem o cliente precisar recarregar nem enviar comprovante.
* Novo: o código Pix e a linha digitável do boleto passam a ir nos e-mails do pedido, com link para a tela de pagamento.
* Novo: boletos da Pagou aceitam Pix, e a tela agora oferece essa opção para quem quiser confirmação imediata.
* Melhoria: boleto pago em atraso, com multa e juros, passa a ser confirmado normalmente, registrando o valor efetivamente pago.
* Melhoria: textos do checkout deixam claro que falta pagar, em vez de sugerir que o pedido já está concluído.
* Correção: o endereço no checkout em blocos não exibe mais marcadores como {number} e {neighborhood} quando o plugin Brazilian Market on WooCommerce está ativo. Nos e-mails e no painel o endereço continua completo, com número e bairro.
* Correção: o QR Code do Pix mantém o tamanho correto mesmo se a folha de estilo do plugin não carregar (cache do navegador, plugins de otimização).
* Melhoria: a tela de pagamento ganhou estilos de impressão, com o código completo e sem os elementos que não fazem sentido no papel.

= 1.2.0 =
* Segurança: validação da assinatura HMAC (X-Pagou-Signature) em todos os webhooks, com proteção contra repetição (replay).
* Segurança: a chave de API deixou de ser gravada nos metadados do pedido.
* Correção: confirmação de pagamento passa a localizar o pedido de forma confiável (por id da cobrança/QRCode), corrigindo pedidos que ficavam presos em "aguardando".
* Correção: conferência do valor pago antes de concluir o pedido.
* Correção: estorno via PIX não é mais disparado com valor incorreto; estorno via boleto passa a detectar erros da API corretamente.
* Correção: geração de boleto no checkout em blocos (Cart/Checkout Blocks), incluindo endereço do pagador.
* Correção: tipo de pessoa (Física/Jurídica) e documento no checkout em blocos.
* Correção: carregamento das traduções e do hook de ativação.
* Compatibilidade declarada com HPOS (High-Performance Order Storage) e com o checkout em blocos.
* Ajustes de tempo de expiração padrão do PIX e diversas correções menores.

= 1.1.2 =
* Ajustes nos logs de erros.

= 1.1.1 =
* Ajustes na geração dos boletos.

= 1.1.0 =
* Configuração manual de tempo de expiração do PIX.

= 1.0.6 =
* Remoção de bibliotecas sem uso.

= 1.0.5 =
* Atualizações de compatibilidades.

= 1.0.4 =
* Atualizações de bibliotecas.
 
= 1.0.3 =
* Ajustes de compatibilidade com checkout legacy, blocks e Elementor.

= 1.0.21 =
* Atualizações com Wordpress.

= 1.0.2 =
* Atualização de bibliotecas e configurações de segurança.

= 1.0.1 =
* Corrigido o domínio de tradução para corresponder ao slug do plugin.
* Adicionada a documentação do uso de serviços externos no arquivo readme.
* Adicionada verificação de `ABSPATH` para evitar acesso direto aos arquivos PHP.

= 1.0.0 =
* Versão inicial do plugin, integração com métodos de pagamento PIX e Boleto via Pagou.

== Upgrade Notice ==

= 2.0.0 =
Nova versão com cartão de crédito, parcelamento, cartão salvo e split de pagamento. As configurações são migradas automaticamente; confira a nova aba WooCommerce > Configurações > Pagou após atualizar.

= 1.3.1 =
Corrige falha na geração de pagamentos em checkouts personalizados e a exibição de descontos como 10% e 20%. Atualização recomendada.

= 1.2.0 =
Atualização de segurança importante e correção da confirmação automática de pagamentos. Recomendada para todas as lojas.
