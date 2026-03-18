(function() {
    if (!window._paykeeperInitializedContainers) {
        window._paykeeperInitializedContainers = new Set();
    }

    const PayKeeperFunctions = (() => {

        // Создаем экземпляр для каждого блока с проверкой на уже инициализированный
        const createInstance = (container) => {
            // Проверяем, не был ли уже инициализирован этот контейнер
            if (window._paykeeperInitializedContainers.has(container)) {
                return null;
            }

            // Помечаем контейнер как инициализированный
            window._paykeeperInitializedContainers.add(container);

            // Конфигурация для конкретного блока
            const config = {
                container: container,
                selectors: {
                    card: '.paykeeper-card',
                    cardLabel: '.paykeeper-card__label',
                    cardRadio: '.paykeeper-card__radio',
                    cardDefaultBadge: '.paykeeper-card__default',
                    typeWrapper: '.paykeeper-card__type-wrapper',
                    actionButton: '.paykeeper-card__action',
                    removeButton: '.paykeeper-card__action--remove',
                    defaultButton: '.paykeeper-card__action--default',
                    submitButton: '.paykeeper__button--pay',
                    form: 'form[name="paykeeper__form"]',
                    ajaxUrlInput: 'input[name="ajaxUrl"]',
                    paymentIdInput: 'input[name="paymentId"]'
                },
                styles: {
                    defaultBorder: '#e9ecef',
                    newCard: {
                        border: '#FFEE00',
                        shadow: '0 2px 8px rgba(255, 238, 0, 0.2)'
                    },
                    savedCard: {
                        border: '#FF003C',
                        shadow: '0 2px 8px rgba(255, 0, 60, 0.1)'
                    }
                },
                messages: {
                    confirmRemove: 'Вы уверены, что хотите удалить эту карту?',
                    confirmRecurrent: 'Вы уверены, что хотите оплатить этой картой?',
                    defaultSet: 'Карта установлена как основная',
                    removeError: 'Ошибка при удалении карты: ',
                    defaultError: 'Ошибка: ',
                    unknownError: 'Неизвестная ошибка',
                    networkError: 'Произошла ошибка при удалении карты',
                    generalError: 'Произошла ошибка',
                    noCardSelected: 'Не выбрана карта',
                    successRecurrent: 'Списание успешно инициировано',
                }
            };

            // Вспомогательные функции для конкретного блока
            const helpers = {
                showAlert(message, type = 'info') {
                    alert(message);
                },

                confirmAction(message) {
                    return confirm(message);
                },

                getCardElement(cardUuid) {
                    return container.querySelector(`#card_${cardUuid}`);
                },

                // Получаем данные карты из кнопки в рамках текущего блока
                getCardDataFromButton(button) {
                    const cardElement = button.closest(config.selectors.card);
                    if (!cardElement) return null;

                    const radio = cardElement.querySelector(config.selectors.cardRadio);
                    if (!radio) return null;

                    return {
                        uuid: radio.getAttribute('data-value'),
                        value: radio.value,
                        default: radio.getAttribute('data-default'),
                    };
                },

                activateLoading(submitButton) {
                    const originalHTML = submitButton.innerHTML;
                    submitButton.setAttribute('data-original-html', originalHTML);

                    submitButton.disabled = true;
                    submitButton.style.opacity = '0.7';
                    submitButton.style.cursor = 'not-allowed';

                    submitButton.innerHTML = `
                    <span class="paykeeper__button-text">Обработка...</span>
                    <div class="paykeeper__loader">
                        <div class="paykeeper__loader-spinner"></div>
                    </div>
                `;
                },

                resetButton(submitButton) {
                    const originalHTML = submitButton.getAttribute('data-original-html');
                    submitButton.disabled = false;
                    submitButton.style.opacity = '';
                    submitButton.style.cursor = '';
                    submitButton.innerHTML = originalHTML || 'Оплатить';
                },

                // Получаем URL для AJAX запросов из текущего блока
                getAjaxUrl() {
                    const input = container.querySelector(config.selectors.ajaxUrlInput);
                    return input ? input.value : '';
                },

                // Получаем paymentId из текущего блока
                getPaymentId() {
                    const input = container.querySelector(config.selectors.paymentIdInput);
                    return input ? input.value : '';
                }
            };

            // Работа с API для конкретного блока
            const api = {
                async request(url, data) {
                    try {
                        const formData = new FormData();

                        Object.keys(data).forEach(key => {
                            formData.append(key, data[key]);
                        });

                        if (typeof BX !== 'undefined') {
                            formData.append('sessid', BX.bitrix_sessid());
                        }
                        formData.append('paymentId', helpers.getPaymentId());

                        const response = await fetch(url, {
                            method: 'POST',
                            body: formData
                        });
                        return await response.json();
                    } catch (error) {
                        console.error('API Error:', error);
                        throw error;
                    }
                },

                async removeCard(cardUuid) {
                    return this.request(helpers.getAjaxUrl(), {
                        action: 'removeCard',
                        card_uuid: cardUuid
                    });
                },

                async setDefaultCard(cardUuid) {
                    return this.request(helpers.getAjaxUrl(), {
                        action: 'setDefaultCard',
                        card_uuid: cardUuid
                    });
                },

                async repeatRecurrent(cardUuid) {
                    return this.request(helpers.getAjaxUrl(), {
                        action: 'repeatRecurrent',
                        card_uuid: cardUuid
                    });
                }
            };

            // Работа с DOM в рамках текущего блока
            const dom = {
                resetCardStyles() {
                    container.querySelectorAll(config.selectors.card).forEach(cardEl => {
                        cardEl.style.borderColor = config.styles.defaultBorder;
                        cardEl.style.boxShadow = 'none';
                    });
                },

                updateCardStyle(cardElement, isNewCard = false) {
                    if (!cardElement) return;

                    const style = isNewCard ? config.styles.newCard : config.styles.savedCard;
                    cardElement.style.borderColor = style.border;
                    cardElement.style.boxShadow = style.shadow;
                },

                removeCardWithAnimation(cardUuid) {
                    const cardElement = helpers.getCardElement(cardUuid);
                    if (!cardElement) return;

                    cardElement.style.opacity = '0.5';

                    setTimeout(() => {
                        cardElement.remove();
                        this.checkCardsCount();
                    }, 300);
                },

                checkCardsCount() {
                    const cards = container.querySelectorAll(config.selectors.card);
                    const otherCard = container.querySelector('.paykeeper-card--other');

                    if (cards.length === 1 && otherCard) {
                        setTimeout(() => location.reload(), 100);
                    }
                },

                addDefaultBadge(cardUuid) {
                    // Удаляем все существующие бейджи в текущем блоке
                    container.querySelectorAll(config.selectors.cardDefaultBadge).forEach(el => {
                        el.remove();
                    });

                    // Добавляем бейдж к указанной карте
                    const currentCard = helpers.getCardElement(cardUuid);
                    if (!currentCard) return;

                    const typeWrapper = currentCard.querySelector(config.selectors.typeWrapper);
                    if (typeWrapper) {
                        const defaultBadge = document.createElement('span');
                        defaultBadge.className = 'paykeeper-card__default';
                        defaultBadge.textContent = 'Основная';
                        typeWrapper.appendChild(defaultBadge);
                    }
                },

                disableDefaultButton(cardUuid) {
                    const cardElement = helpers.getCardElement(cardUuid);
                    if (!cardElement) return;

                    container.querySelectorAll(config.selectors.defaultButton).forEach(el => {
                        el.style.display = 'flex';
                    });

                    const defaultButton = cardElement.querySelector(config.selectors.defaultButton);
                    if (defaultButton) {
                        defaultButton.style.display = 'none';
                    }
                },

                // Получает radio кнопку по ID карты в текущем блоке
                getRadioByCardUuid(cardUuid) {
                    return container.querySelector(`#card_input_${cardUuid}`);
                }
            };

            // Обработчики событий для текущего блока
            const handlers = {
                onCardLabelClick(e) {
                    if (e.target.closest(config.selectors.actionButton)) {
                        return;
                    }

                    const label = e.currentTarget;
                    const radioId = label.getAttribute('for');
                    const radio = container.querySelector(`#${radioId}`);

                    if (!radio) return;

                    radio.checked = true;
                    dom.resetCardStyles();

                    const selectedCard = label.closest(config.selectors.card);
                    if (selectedCard) {
                        const isNewCard = radio.value === 'new';
                        dom.updateCardStyle(selectedCard, isNewCard);
                    }
                },

                // Удаление карты в текущем блоке
                async onRemoveCardClick(button) {
                    const cardData = helpers.getCardDataFromButton(button);
                    if (!cardData) return;

                    if (!helpers.confirmAction(config.messages.confirmRemove)) {
                        return;
                    }

                    try {
                        const data = await api.removeCard(cardData.value);

                        if (data.success) {
                            dom.removeCardWithAnimation(cardData.uuid);
                        } else {
                            helpers.showAlert(`${config.messages.removeError}${data.message || config.messages.unknownError}`);
                        }
                    } catch (error) {
                        console.error('Error:', error);
                        helpers.showAlert(config.messages.networkError);
                    }
                },

                // Установка карты как основной в текущем блоке
                async onDefaultCardClick(button) {
                    const cardData = helpers.getCardDataFromButton(button);
                    if (!cardData) return;

                    const radio = dom.getRadioByCardUuid(cardData.uuid);
                    if (radio) {
                        radio.checked = true;
                        const cardElement = helpers.getCardElement(cardData.uuid);
                        if (cardElement) {
                            dom.resetCardStyles();
                            dom.updateCardStyle(cardElement, false);
                        }
                    }

                    try {
                        const data = await api.setDefaultCard(cardData.value);

                        if (data.success) {
                            dom.addDefaultBadge(cardData.uuid);
                            dom.disableDefaultButton(cardData.uuid);
                            helpers.showAlert(config.messages.defaultSet);
                        } else {
                            helpers.showAlert(`${config.messages.defaultError}${data.message || config.messages.unknownError}`);
                        }
                    } catch (error) {
                        console.error('Error:', error);
                        helpers.showAlert(config.messages.generalError);
                    }
                },

                async onRepeatRecurrentClick(button, cardUuid) {
                    try {
                        const data = await api.repeatRecurrent(cardUuid);

                        if (data.success) {
                            helpers.showAlert(config.messages.successRecurrent);
                            container.querySelector('.paykeeper__block').innerHTML = template.success();
                        } else {
                            helpers.resetButton(button);
                            helpers.showAlert(`${config.messages.defaultError}${data.error || config.messages.unknownError}`);
                        }
                    } catch (error) {
                        helpers.resetButton(button);
                        console.error('Error:', error);
                        helpers.showAlert(config.messages.generalError);
                    }
                },

                // Обработчик кликов в рамках текущего блока
                onContainerClick(e) {
                    const removeButton = e.target.closest(config.selectors.removeButton);
                    if (removeButton) {
                        e.preventDefault();
                        e.stopPropagation();
                        this.onRemoveCardClick(removeButton);
                        return;
                    }

                    const defaultButton = e.target.closest(config.selectors.defaultButton);
                    if (defaultButton) {
                        e.preventDefault();
                        e.stopPropagation();
                        this.onDefaultCardClick(defaultButton);
                        return;
                    }
                },

                onSubmitPaymentForm(e) {
                    const form = e.currentTarget;
                    const submitButton = form.querySelector(config.selectors.submitButton);
                    helpers.activateLoading(submitButton);

                    const formData = new FormData(form);
                    var isNewPayment = false;

                    for (var pair of formData.entries()) {
                        if (pair[0] === 'recurrentid' && pair[1] === 'new') {
                            isNewPayment = true;
                        }
                    }

                    if (!isNewPayment) {
                        e.preventDefault();

                        const checkedRadio = form.querySelector(`${config.selectors.cardRadio}:checked`);
                        if (checkedRadio) {
                            this.onRepeatRecurrentClick(submitButton, checkedRadio.value);
                        }
                    }
                }
            };

            const template = {
                success(){
                    return `<div class="paykeeper__success">
                    <div class="paykeeper__success-icon">
                        <svg width="64" height="64" viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M32 58.6667C46.7276 58.6667 58.6667 46.7276 58.6667 32C58.6667 17.2724 46.7276 5.33337 32 5.33337C17.2724 5.33337 5.33337 17.2724 5.33337 32C5.33337 46.7276 17.2724 58.6667 32 58.6667Z" fill="#28A745" fill-opacity="0.1"/>
                            <path d="M32 56C45.2548 56 56 45.2548 56 32C56 18.7452 45.2548 8 32 8C18.7452 8 8 18.7452 8 32C8 45.2548 18.7452 56 32 56Z" stroke="#28A745" stroke-width="2"/>
                            <path d="M24 32L30 38L40 26" stroke="#28A745" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                    <h2 class="paykeeper__success-title">Списание успешно инициировано</h2>
                    <div class="paykeeper__success-message">
                        <p>Запрос на списание средств с выбранной карты был успешно отправлен в банк.</p>
                        <p>Деньги будут списаны в течение нескольких минут.</p>
                    </div>
                </div>`;
                }
            };

            // Инициализация конкретного блока
            const init = () => {
                // Обработчики для кликов по карточкам в текущем блоке
                container.querySelectorAll(config.selectors.cardLabel).forEach(label => {
                    label.addEventListener('click', handlers.onCardLabelClick);
                });

                // Форма в текущем блоке
                const form = container.querySelector(config.selectors.form);
                if (form) {
                    form.addEventListener('submit', handlers.onSubmitPaymentForm.bind(handlers));
                }

                // Делегирование событий для кнопок действий в текущем блоке
                container.addEventListener('click', handlers.onContainerClick.bind(handlers));

                // Инициализация выбранной карты в текущем блоке
                const checkedRadio = container.querySelector(`${config.selectors.cardRadio}:checked`);
                if (checkedRadio) {
                    const checkedLabel = container.querySelector(`label[for="${checkedRadio.id}"]`);
                    if (checkedLabel) {
                        const checkedCard = checkedLabel.closest(config.selectors.card);
                        if (checkedCard) {
                            const isNewCard = checkedRadio.value === 'new';
                            dom.updateCardStyle(checkedCard, isNewCard);
                        }
                    }
                }

                container.querySelectorAll(config.selectors.defaultButton).forEach(el => {
                    const cardData = helpers.getCardDataFromButton(el);
                    if (!cardData) return;

                    if (cardData.default === 'Y') {
                        el.style.display = 'none';
                    } else {
                        el.style.display = 'flex';
                    }
                });

                // const defaultButton = cardElement.querySelector(config.selectors.defaultButton);
                // if (defaultButton) {
                //     defaultButton.style.display = 'none';
                // }
                // const checkedRadio = container.querySelector(`${config.selectors.cardRadio}:checked`);
                // const defaultButton = cardElement.querySelector(config.selectors.defaultButton);
                // if (defaultButton) {
                //     defaultButton.style.display = 'none';
                // }
            };

            // Инициализируем блок
            init();

            // Возвращаем публичное API для этого блока
            return {
                container,
                removeCard: handlers.onRemoveCardClick,
                setAsDefault: handlers.onDefaultCardClick
            };
        };

        // Основная функция инициализации всех блоков с защитой от повторной инициализации
        const initAll = () => {
            // Находим все блоки и создаем для каждого экземпляр
            const wrappers = document.querySelectorAll('.paykeeper__wrapp');
            const instances = [];

            wrappers.forEach((wrapper, index) => {
                const instance = createInstance(wrapper);
                if (instance) {
                    instances.push(instance);
                }
            });

            return instances;
        };

        // Публичное API
        return {
            init: initAll,
            createInstance
        };
    })();

// Запускаем инициализацию
    PayKeeperFunctions.init();
})();