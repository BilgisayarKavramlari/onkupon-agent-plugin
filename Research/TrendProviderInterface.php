<?php
namespace OnKupon\Agent\Research; interface TrendProviderInterface { public function trends(array $options=[]): array; }