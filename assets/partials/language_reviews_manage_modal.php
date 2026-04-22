<?php
declare(strict_types=1);
?>
<div class="sf-modal hidden" id="modalLanguageReviewsManage" role="dialog" aria-modal="true" aria-labelledby="modalLanguageReviewsTitle">
    <div class="sf-modal-content sf-language-reviews-modal">
        <h2 id="modalLanguageReviewsTitle">
            <?= htmlspecialchars(sf_term('language_reviews_modal_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </h2>

        <div class="sf-language-reviews-info">
            ℹ️ <?= htmlspecialchars(sf_term('language_reviews_modal_info', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </div>
        <div class="sf-language-reviews-persist-info">
            <?= htmlspecialchars(sf_term('language_reviews_persists_info', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </div>

        <div id="sfLanguageReviewRows" class="sf-language-review-rows" aria-live="polite"></div>

        <div class="sf-field">
            <label class="sf-label" for="sfLanguageReviewMessage">
                <?= htmlspecialchars(sf_term('language_reviews_message_label', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </label>
            <textarea
                id="sfLanguageReviewMessage"
                class="sf-textarea"
                rows="4"
                maxlength="2000"
                placeholder="<?= htmlspecialchars(sf_term('language_reviews_message_placeholder', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
            ></textarea>
            <div class="sf-language-review-counter">
                <span id="sfLanguageReviewCounter">0 / 2000</span>
            </div>
        </div>

        <div class="sf-modal-actions">
            <button type="button" class="sf-btn sf-btn-secondary" data-modal-close="modalLanguageReviewsManage">
                <?= htmlspecialchars(sf_term('btn_cancel', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <button type="button" class="sf-btn sf-btn-primary" id="sfLanguageReviewsSubmitBtn">
                <?= htmlspecialchars(sf_term('language_reviews_submit', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
    </div>
</div>
