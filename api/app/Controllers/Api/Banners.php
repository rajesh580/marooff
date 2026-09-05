<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\BannerModel;

class Banners extends BaseController
{
    public function index()
    {
        $placement = (string) ($this->request->getGet('placement') ?: 'home_hero');
        $rows = (new BannerModel())->activeForPlacement($placement);
        return $this->ok($rows);
    }
}
