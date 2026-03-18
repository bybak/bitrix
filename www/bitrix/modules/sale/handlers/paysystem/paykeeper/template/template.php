<?php

/**
 * @var $params array
 */

if (!isset($params)) { ?>
    <div class="paykeeper__wrapp">
        <div class="paykeeper__block">
            <p class="paykeeper__error">Missing data $params</p>
        </div>
    </div>
<?php } elseif ($params["form_type"] == "create") { ?>
    <div class="paykeeper__wrapp">
        <div class="paykeeper__block">
            <?php if (!$params['is_recurrent']): ?>
            <p class="paykeeper__text"><?=$params["detail_page_message"]?></p>
            <?php endif; ?>
            <?php if (isset($params["sum"]) && $params["sum"] > 0): ?>
                <div class="paykeeper__amount">
                    <span class="paykeeper__amount-label">Сумма к оплате:</span>
                    <span class="paykeeper__amount-value"><?=number_format($params["sum"], 2, '.', ' ')?> ₽</span>
                    <input id="paykeeper__ajaxurl" type="hidden" name="ajaxUrl" value="<?=$params['ajax_php_url']?>"/>
                    <input id="paykeeper__paymentid" type="hidden" name="paymentId" value="<?=$params['paymentid']?>"/>
                </div>
            <?php endif; ?>
            <form name="paykeeper__form" action="<?=$params["form_url"]?>" accept-charset="utf-8" method="post">
                <input type="hidden" name="sum" value="<?=$params["sum"]?>"/>
                <input type="hidden" name="orderid" value="<?=$params["orderid"]?>"/>
                <?php if ($params["contacts"]) { ?>
                <label for="pk_name">Укажите ФИО</label><br>
                <input id="pk_name" type="text" name="clientid" value="" placeholder="Введите ФИО" required/><br>
                <label for="pk_email">Укажите Email</label><br>
                <input id="pk_email" type="text" name="client_email" value="" placeholder="Введите Email" required/><br>
                <label for="pk_phone">Укажите телефон</label><br>
                <input id="pk_phone" type="text" name="client_phone" value="" placeholder="Введите телефон" required/>
                <?php } else { ?>
                <input type="hidden" name="clientid" value="<?=$params["clientid"]?>"/>
                <input type="hidden" name="client_email" value="<?=$params["client_email"]?>"/>
                <input type="hidden" name="client_phone" value="<?=$params["client_phone"]?>"/>
                <?php } ?>
                <input type="hidden" name="service_name" value="<?=$params["service_name"]?>"/>
                <input type="hidden" name="cart" value = '<?=htmlentities($params["cart"],ENT_QUOTES)?>'/>
                <?php if (isset($params["sbp"])) { ?>
                    <input type="hidden" name="pstype" value = "sbp_default"/>
                <?php } else if (isset($params["pstype"])) { ?>
                    <input type="hidden" name="pstype" value = "<?=$params["pstype"]?>"/>
                <?php } ?>
                <?php if (isset($params["user_result_callback"])) { ?>
                    <input type="hidden" name="user_result_callback" value="<?=$params["user_result_callback"]?>"/>
                <?php } ?>
                <?php if (isset($params["msgtype"])) { ?>
                    <input type="hidden" name="msgtype" value="<?=$params["msgtype"]?>"/>
                <?php } ?>
                <input type="hidden" name="lang" value="<?=$params["lang"]?>"/>
                <input type="hidden" name="sign" value="<?=$params["sign"]?>"/>

                <?php if (!empty($params["card_list"])): ?>
                <div class="paykeeper-cards">
                    <h3 class="paykeeper-cards__title">Выберите карту для оплаты</h3>
                    <?php foreach ($params["card_list"] as $index => $card): ?>
                        <div class="paykeeper-card" id="card_<?=$cardRand=md5(rand());?>">
                            <div class="paykeeper-card__main">
                                <input type="radio" name="recurrentid"
                                       id="card_input_<?=$cardRand?>"
                                       data-value="<?=$cardRand?>"
                                       data-default="<?=(isset($card['IS_DEFAULT']) && $card['IS_DEFAULT']) ? 'Y' : 'N'?>"
                                       value="<?=$card['HASH']?>"
                                    <?=($index === 0) ? 'checked' : ''?>
                                       class="paykeeper-card__radio">

                                <label for="card_input_<?=$cardRand?>" class="paykeeper-card__label">
                                    <div class="paykeeper-card__info">
                                        <div class="paykeeper-card__type">
                                            <?php
                                            $cardType = 'VISA';
                                            $cardNumber = '';

                                            if (isset($card['PK_CARD_NUMBER']) && !empty($card['PK_CARD_NUMBER'])) {
                                                $cardNumber = preg_replace('/(.{4})(?=.{4})/', '$1 ', $card['PK_CARD_NUMBER']);
                                                $firstDigit = substr($cardNumber, 0, 1);
                                                if ($firstDigit == '4') $cardType = 'VISA';
                                                elseif ($firstDigit == '5') $cardType = 'MasterCard';
                                                elseif ($firstDigit == '2' || $firstDigit == '6') $cardType = 'MIR';
                                            } else {
                                                $cardNumber = 'номер не найден';
                                            }
                                            ?>

                                            <div class="paykeeper-card__type-wrapper">
                                    <span class="paykeeper-card__type-logo paykeeper-card__type-logo--<?=strtolower($cardType)?>">
                                        <?=$cardType?>
                                    </span>
                                                <?php if (isset($card['IS_DEFAULT']) && $card['IS_DEFAULT']): ?>
                                                <span class="paykeeper-card__default">Основная</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <div class="paykeeper-card__number">
                                            <span class="paykeeper-card__number-text"><?=$cardNumber?></span>
                                        </div>

                                        <?php if (isset($card['EXPIRY_DATE'])): ?>
                                        <div class="paykeeper-card__expiry">
                                            Действует до: <?=$card['EXPIRY_DATE']?>
                                        </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="paykeeper-card__selector">
                                        <span class="paykeeper-card__selector-circle"></span>
                                    </div>
                                </label>
                            </div>

                            <div class="paykeeper-card__actions">
                                <button type="button"
                                        class="paykeeper-card__action paykeeper-card__action--remove"
                                        title="Удалить карту">
                                    <span class="paykeeper-card__action-text">Удалить</span>
                                    <svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M10 4L4 10M4 4L10 10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                                    </svg>
                                </button>
                                <button type="button"
                                        class="paykeeper-card__action paykeeper-card__action--default"
                                        title="Сделать основной">
                                    <span class="paykeeper-card__action-text">Основная?</span>
                                    <svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M2 7L5.5 10.5L12 4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                                    </svg>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <!-- Опция "Оплатить другой картой" -->
                    <div class="paykeeper-card paykeeper-card--other">
                        <div class="paykeeper-card__main">
                            <input type="radio"
                                   name="recurrentid"
                                   id="card_input_other_<?=$cardRand?>"
                                   value="new"
                                   class="paykeeper-card__radio">

                            <label for="card_input_other_<?=$cardRand?>" class="paykeeper-card__label">
                                <div class="paykeeper-card__info">
                                    <div class="paykeeper-card__type">
                                        <div class="paykeeper-card__type-wrapper">
                                    <span class="paykeeper-card__type-logo paykeeper-card__type-logo--other">
                                        +
                                    </span>
                                        </div>
                                    </div>

                                    <div class="paykeeper-card__number">
                                        <span class="paykeeper-card__number-text">Оплатить другой картой</span>
                                    </div>
                                </div>

                                <div class="paykeeper-card__selector">
                                    <span class="paykeeper-card__selector-circle"></span>
                                </div>
                            </label>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <input type="hidden" name="recurrentid" value="new">
                <?php endif; ?>
                <div class="paykeeper__submit">
                    <button type="submit" class="paykeeper__button paykeeper__button--pay">
                        <span class="paykeeper__button-text"><?=$params["pay_button"]?></span>
                        <?php if (isset($params["sum"])): ?>
                            <span class="paykeeper__button-sum"><?=number_format($params["sum"], 2, '.', ' ')?> ₽</span>
                        <?php endif; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
    <!-- script -->
    <script src="<?=$params['ajax_js_url']?>?<?=md5(rand());?>"></script>
    <script>
        <?php if (!$params['is_recurrent'] && $params["redirect"]): ?>
            setTimeout(function() {
                document.forms["paykeeper__form"].submit();
            }, 2000);
        <?php endif; ?>
    </script>
<?php } else { ?>
    <div class="paykeeper__wrapp">
        <div class="paykeeper__block">
            <?=$params["detail_page_message"]?>
        </div>
    </div>
<?php } ?>
<style>
    .paykeeper__wrapp {
        max-width: 500px;
        margin: 0 auto;
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    }

    .paykeeper__block {
        background: white;
        border-radius: 12px;
        padding: 24px;
        box-shadow: 0 2px 10px rgba(49, 49, 49, 0.1);
    }

    .paykeeper__amount {
        background: #f8f9fa;
        border-radius: 8px;
        padding: 16px;
        text-align: center;
        margin-bottom: 20px;
        border: 1px solid #e9ecef;
    }

    .paykeeper__amount-label {
        font-size: 14px;
        color: #313131;
        display: block;
        margin-bottom: 4px;
        font-weight: 500;
    }

    .paykeeper__amount-value {
        font-size: 24px;
        font-weight: 700;
        color: #313131;
    }

    .paykeeper-cards__title {
        font-size: 18px;
        color: #313131;
        margin: 0 0 20px 0;
        font-weight: 600;
        text-align: center;
    }

    .paykeeper-card {
        background: white;
        border: 2px solid #e9ecef;
        border-radius: 8px;
        margin-bottom: 16px;
        transition: all 0.2s ease;
        overflow: hidden;
    }

    .paykeeper-card:hover {
        border-color: #FF003C;
        box-shadow: 0 2px 8px rgba(255, 0, 60, 0.1);
    }

    .paykeeper-card--other:hover {
        border-color: #FFEE00;
        box-shadow: 0 2px 8px rgba(255, 238, 0, 0.2);
    }

    .paykeeper-card__main {
        display: flex;
        align-items: center;
        padding: 16px;
    }

    .paykeeper-card__radio {
        display: none;
    }

    .paykeeper-card__radio:checked + .paykeeper-card__label .paykeeper-card__selector-circle {
        background: #FF003C;
        border-color: #FF003C;
    }

    .paykeeper-card__radio:checked + .paykeeper-card__label .paykeeper-card__selector-circle::after {
        content: '';
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        width: 6px;
        height: 6px;
        background: white;
        border-radius: 50%;
    }

    .paykeeper-card__radio[value="new"]:checked + .paykeeper-card__label .paykeeper-card__selector-circle {
        background: #FFEE00;
        border-color: #FFEE00;
    }

    .paykeeper-card__radio[value="new"]:checked + .paykeeper-card__label .paykeeper-card__selector-circle::after {
        background: #313131;
    }

    .paykeeper-card__label {
        display: flex;
        justify-content: space-between;
        align-items: center;
        cursor: pointer;
        width: 100%;
        gap: 16px;
    }

    .paykeeper-card__info {
        display: flex;
        flex-direction: column;
        gap: 6px;
        flex-grow: 1;
    }

    .paykeeper-card__type-wrapper {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .paykeeper-card__type-logo {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 4px;
        font-size: 12px;
        font-weight: 600;
        color: white;
    }

    .paykeeper-card__type-logo--visa {
        background: #1a1f71;
    }

    .paykeeper-card__type-logo--mastercard {
        background: #eb001b;
    }

    .paykeeper-card__type-logo--mir {
        background: #0a5cb4;
    }

    .paykeeper-card__type-logo--other {
        background: #FFEE00;
        color: #313131;
        font-size: 14px;
        padding: 4px 10px;
    }

    .paykeeper-card__default {
        font-size: 12px;
        color: #FF003C;
        background: rgba(255, 0, 60, 0.1);
        padding: 2px 8px;
        border-radius: 4px;
        font-weight: 500;
    }

    .paykeeper-card__number {
        font-size: 18px;
        color: #313131;
        font-weight: 600;
        letter-spacing: 1px;
    }

    .paykeeper-card__number-text {
        font-family: 'Courier New', monospace;
    }

    .paykeeper-card--other .paykeeper-card__number-text {
        font-family: inherit;
        font-size: 16px;
        letter-spacing: normal;
        color: #313131;
        font-weight: 600;
    }

    .paykeeper-card__expiry {
        font-size: 12px;
        color: #666;
    }

    .paykeeper-card__selector {
        flex-shrink: 0;
    }

    .paykeeper-card__selector-circle {
        display: block;
        width: 20px;
        height: 20px;
        border: 2px solid #adb5bd;
        border-radius: 50%;
        position: relative;
        transition: all 0.2s ease;
    }

    .paykeeper-card__actions {
        display: flex;
        border-top: 1px solid #e9ecef;
        background: #f8f9fa;
        padding: 12px 16px;
        gap: 12px;
    }

    .paykeeper-card__action {
        flex: 1;
        background: white;
        border: 1px solid #dee2e6;
        border-radius: 6px;
        padding: 10px 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        cursor: pointer;
        color: #313131;
        transition: all 0.2s ease;
        font-size: 14px;
        font-weight: 500;
        min-height: 44px;
    }

    .paykeeper-card__action:hover {
        transform: translateY(-1px);
    }

    .paykeeper-card__action--remove {
        border-color: #FF003C;
        color: #FF003C;
    }

    .paykeeper-card__action--remove:hover {
        background: #FF003C;
        color: white;
        border-color: #FF003C;
    }

    .paykeeper-card__action--default {
        border-color: #FFEE00;
        color: #313131;
        background: #FFEE00;
    }

    .paykeeper-card__action--default:hover {
        background: #ffd900;
        border-color: #ffd900;
    }

    .paykeeper-card__action-text {
        flex-grow: 1;
        text-align: center;
    }

    .paykeeper__submit {
        margin-top: 24px;
    }

    .paykeeper__button {
        width: 100%;
        border: none;
        border-radius: 8px;
        padding: 16px;
        font-size: 16px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s ease;
        display: flex;
        justify-content: space-between;
        align-items: center;
        text-align: center;
        min-height: 56px;
    }

    .paykeeper__button--pay {
        background: #FF003C;
        color: white;
    }

    .paykeeper__button--pay:hover {
        background: #e00034;
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(255, 0, 60, 0.2);
    }

    .paykeeper__button--pay:active {
        background: #c2002d;
        transform: translateY(0);
    }

    .paykeeper__button-text {
        flex-grow: 1;
        text-align: left;
    }

    .paykeeper__button-sum {
        background: rgba(255, 255, 255, 0.3);
        padding: 6px 14px;
        border-radius: 20px;
        font-size: 14px;
        font-weight: 600;
    }

    /* success */

    .paykeeper__success {
        text-align: center;
        padding: 20px 0;
    }

    .paykeeper__success-icon {
        margin-bottom: 24px;
        animation: success-appear 0.6s ease-out;
    }

    .paykeeper__success-title {
        font-size: 24px;
        color: #313131;
        margin: 0 0 16px 0;
        font-weight: 600;
        line-height: 1.3;
    }

    .paykeeper__success-message {
        background: #f8f9fa;
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 24px;
        border: 1px solid #e9ecef;
        text-align: left;
    }

    .paykeeper__success-message p {
        margin: 0 0 12px 0;
        color: #313131;
        font-size: 16px;
        line-height: 1.5;
    }

    .paykeeper__success-message p:last-child {
        margin-bottom: 0;
    }

    @keyframes success-appear {
        0% {
            opacity: 0;
            transform: scale(0.8);
        }
        100% {
            opacity: 1;
            transform: scale(1);
        }
    }

    /* button loading */

    .paykeeper__loader {
        display: inline-flex;
        align-items: center;
        margin-left: 12px;
    }

    .paykeeper__loader-spinner {
        width: 20px;
        height: 20px;
        border: 2px solid rgba(255, 255, 255, 0.3);
        border-top: 2px solid white;
        border-radius: 50%;
        animation: paykeeper-spin 1s linear infinite;
    }

    @keyframes paykeeper-spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }

    .paykeeper__button--pay[disabled] {
        background: #cccccc !important;
        transform: none !important;
        box-shadow: none !important;
    }

    .paykeeper__button--pay[disabled]:hover {
        background: #cccccc !important;
        transform: none !important;
        box-shadow: none !important;
    }

    @media (max-width: 480px) {
        .paykeeper__block {
            padding: 16px;
        }

        .paykeeper__amount {
            padding: 14px;
        }

        .paykeeper__amount-value {
            font-size: 22px;
        }

        .paykeeper-cards__title {
            font-size: 16px;
            margin-bottom: 16px;
        }

        .paykeeper-card__main {
            padding: 14px;
        }

        .paykeeper-card__number {
            font-size: 16px;
        }

        .paykeeper-card--other .paykeeper-card__number-text {
            font-size: 15px;
        }

        .paykeeper-card__actions {
            flex-direction: column;
            gap: 8px;
            padding: 14px;
        }

        .paykeeper-card__action {
            min-height: 48px;
            padding: 12px;
            font-size: 15px;
        }

        .paykeeper__submit {
            margin-top: 20px;
        }

        .paykeeper__button {
            padding: 14px;
            min-height: 52px;
            font-size: 15px;
        }

        /* success */

        .paykeeper__success {
            padding: 16px 0;
        }

        .paykeeper__success-icon {
            margin-bottom: 20px;
        }

        .paykeeper__success-icon svg {
            width: 56px;
            height: 56px;
        }

        .paykeeper__success-title {
            font-size: 20px;
            margin-bottom: 14px;
        }

        .paykeeper__success-message {
            padding: 16px;
            margin-bottom: 20px;
        }

        .paykeeper__success-message p {
            font-size: 15px;
        }
    }
</style>