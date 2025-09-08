=== Pagou - Payments for WooCommerce ===
Contributors: pagoupagamentos, alissonic
Donate link: https://pagou.com.br/
Tags: woocommerce, pagou, pagamentos, pix, boleto
Requires at least: 6.1
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.1.2
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Pagamentos via PIX e boletos bancários no WooCommerce.

== Supported Languages ==
* English (en_US)
* Portuguese (Brazil) (pt_BR)

== Description ==
O plugin "Pagou - Pagamentos para WooCommerce" integra o WooCommerce ao gateway de pagamento Pagou, permitindo a utilização de PIX e Boleto como métodos de pagamento.

== External services ==

Este plugin utiliza os seguintes serviços externos:

1. **Pagou API**
   - **Uso:** Processamento de pagamentos via PIX e boleto usando ambiente de homologação e produção.
   - **Dados Enviados:** Informações de transações financeiras, incluindo valores, produtos e dados do cliente.
   - **Quando:** Durante a criação, consulta e reembolso de pagamentos.
   - **Termos de Uso:** [Link para os termos de uso da Pagou](https://pagou.com.br/termos).
   - **Política de Privacidade:** [Link para a política de privacidade da Pagou](https://pagou.com.br/privacidade).
   - **Dados enviados para:** [Homolog](https://sandbox-api.pagou.com.br) ou [produção](https://api.pagou.com.br) da Pagou.

2. **ViaCEP**
   - **Uso:** Busca de endereço a partir do CEP informado.
   - **Dados Enviados:** CEP digitado pelo usuário.
   - **Quando:** No momento do preenchimento do endereço no checkout.
   - **Termos de Uso:** [Termos de Uso do ViaCEP](https://viacep.com.br/sobre/).

== Política de Privacidade e Termos de Uso ==
- [Termos de Uso do Pagou](https://pagou.com.br/termos)
- [Política de Privacidade do Pagou](https://pagou.com.br/privacidade)

== Installation ==
1. Faça o upload do plugin para o diretório `/wp-content/plugins/`.
2. Ative o plugin através do menu "Plugins" no WordPress.
3. Acesse as configurações de WooCommerce para configurar os métodos de pagamento Pagou (PIX e Boleto).

== Frequently Asked Questions (FAQ) ==
= Como configuro os pagamentos via PIX e Boleto? 
Após ativar o plugin, vá até as configurações de pagamento do WooCommerce e habilite as opções de pagamento "Pagou" para PIX e Boleto.

= Quais são os requisitos mínimos para usar o plugin? =
Você precisa de pelo menos o WordPress 6.1 e PHP 7.4 para rodar o plugin.

== Changelog ==
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
= 1.1.2 =
Novos recursos e verificações de erros.

== License ==
Este plugin está licenciado sob a GPLv2 ou posterior.
