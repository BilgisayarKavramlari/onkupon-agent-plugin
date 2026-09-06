<?php
namespace OnKupon\Agent\Publishing; class ArticleBuilder { public function build(array $a): array { $a["body"]=wp_kses_post($a["body"]??""); return $a; } }