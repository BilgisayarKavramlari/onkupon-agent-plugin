<?php
namespace OnKupon\Agent\Publishing; class InternalLinkingEngine { public function insert_links(string $html,array $product_ids): string { foreach($product_ids as $id){ $html .= "
" . do_shortcode('[onkupon_agent_product_card id="'.absint($id).'"]'); } return $html; } }