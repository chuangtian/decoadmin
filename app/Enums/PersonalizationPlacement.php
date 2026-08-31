<?php

namespace App\Enums;

enum PersonalizationPlacement: string
{
    case Homepage = 'homepage';
    case ProductPage = 'product_page';
    case CartPage = 'cart_page';
    case SmartCart = 'smart_cart';
    case Checkout = 'checkout';
}
