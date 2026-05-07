<?php
const MF_HIDE_TITLEBAR = true;
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
$APPLICATION->SetTitle("Документы");
$APPLICATION->SetPageProperty("HIDE_SIDEBAR", "Y");

$mfDocuments = [
	[
		"href" => "//s.siteapi.org/ccdb0156d66a088.ru/docs/3zdm6lyqt1a84okc0gcwg0ossk4kkc",
		"ext"  => "doc",
		"title" => "АКТ ПРИЕМА-ПЕРЕДАЧИ.doc",
		"meta" => "33 кб · 07 апреля в 17:46",
	],
	[
		"href" => "//s.siteapi.org/ccdb0156d66a088.ru/docs/np2w5uw9vc0kgc0c8gw4w8088gwo0c",
		"ext"  => "docx",
		"title" => "ПРАВИЛА ПРОКАТА.docx",
		"meta" => "17.6 кб · 07 апреля в 17:46",
	],
	[
		"href" => "//s.siteapi.org/ccdb0156d66a088.ru/docs/1fucfkiriq3osoo4wgkg4wcosokswc",
		"ext"  => "doc",
		"title" => "ДОГОВОР АРЕНДЫ.doc",
		"meta" => "92.5 кб · 07 апреля в 17:45",
	],
];
?>

<div class="mf-documents mf-text-page mb-4">
	<header class="mf-documents-hero">
		<div class="mf-documents-hero__icon" aria-hidden="true"><?= mf_icon('doc', ['class' => 'mf-icon mf-icon--xl']) ?></div>
		<h1>Документы</h1>
		<p>Шаблоны договора аренды, акта приёма-передачи и правил проката — для скачивания и предварительного ознакомления.</p>
	</header>

	<div class="mf-documents-grid">
		<?php foreach ($mfDocuments as $doc): ?>
			<a class="mf-documents-item" href="<?= htmlspecialchars($doc["href"], ENT_QUOTES) ?>" target="_blank" rel="nofollow noopener">
				<div class="mf-documents-icon" data-ext="<?= htmlspecialchars($doc["ext"], ENT_QUOTES) ?>">
					<?= mf_icon('doc', ['class' => 'mf-icon mf-icon--lg']) ?>
					<span class="mf-documents-icon__ext"><?= htmlspecialchars($doc["ext"], ENT_QUOTES) ?></span>
				</div>
				<div class="mf-documents-text">
					<div class="mf-documents-title"><?= htmlspecialchars($doc["title"], ENT_QUOTES, "UTF-8") ?></div>
					<div class="mf-documents-desc"><?= htmlspecialchars($doc["meta"], ENT_QUOTES, "UTF-8") ?></div>
				</div>
				<div class="mf-documents-action" aria-hidden="true">
					<?= mf_icon('download') ?>
				</div>
			</a>
		<?php endforeach; ?>
	</div>
</div>

<?php require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php"); ?>
