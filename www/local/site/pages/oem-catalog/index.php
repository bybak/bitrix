<?php
define('HIDE_SIDEBAR', true);
require($_SERVER['DOCUMENT_ROOT'].'/bitrix/header.php');
$APPLICATION->SetTitle('Каталог схем запчастей');
$APPLICATION->SetPageProperty('title', 'Каталог схем запчастей Motor Force');
$APPLICATION->SetPageProperty('description', 'OEM каталог схем запчастей: Husqvarna, KTM, Lynx, BRP — навигация по дереву как на Remotors.');
?>

<div class="mf-oem-page" id="mfOemCatalog" data-api-base="/api/oem" data-assets-base="/oem-assets">
	<div class="mf-oem-loading" v-if="bootLoading">Загружаем каталог...</div>

	<div class="mf-oem-error" v-if="error">
		<strong>Не удалось загрузить данные.</strong>
		<span>{{ error }}</span>
		<button type="button" @click="reloadCurrentStep">Повторить</button>
	</div>

	<div class="mf-oem-app" v-cloak>
		<div class="mf-oem-sidebar">
			<div class="mf-oem-step" :class="{ '-active': step === 'root', '-done': selected.root }">
				<span>1</span>
				<div>
					<strong>Бренд</strong>
					<small>{{ selected.root ? selected.root.name : 'Не выбран' }}</small>
				</div>
			</div>
			<div class="mf-oem-step" :class="{ '-active': step === 'browse', '-done': selected.navStack.length }">
				<span>2</span>
				<div>
					<strong>Каталог</strong>
					<small>{{ selected.navNode ? selected.navNode.title : 'Папки' }}</small>
				</div>
			</div>
			<div class="mf-oem-step" :class="{ '-active': step === 'variant' || step === 'assembly', '-done': selected.variant }">
				<span>3</span>
				<div>
					<strong>Модификация</strong>
					<small>{{ selected.variant ? variantTitle(selected.variant) : 'Не выбрана' }}</small>
				</div>
			</div>
			<div class="mf-oem-step" :class="{ '-active': step === 'assembly' || step === 'diagram', '-done': selected.assembly }">
				<span>4</span>
				<div>
					<strong>Узел</strong>
					<small>{{ selected.assembly ? selected.assembly.title : 'Не выбран' }}</small>
				</div>
			</div>
		</div>

		<div class="mf-oem-content">
			<div class="mf-oem-breadcrumbs" v-if="breadcrumbs.length">
				<button type="button" v-for="(crumb, idx) in breadcrumbs" :key="idx" @click="goToCrumb(crumb)">
					{{ crumb.label }}
				</button>
			</div>

			<section v-if="step === 'root'" class="mf-oem-panel">
				<div class="mf-oem-panel-head">
					<h2>Выберите бренд</h2>
					<p>Каталог повторяет структуру Remotors: Husqvarna, KTM, Lynx, BRP.</p>
				</div>
				<div class="mf-oem-grid">
					<button class="mf-oem-card" type="button" v-for="item in catalogRoots" :key="item.id" @click="selectRoot(item)">
						<strong>{{ item.name }}</strong>
						<span>{{ item.arib_code }}</span>
					</button>
				</div>
			</section>

			<section v-if="step === 'browse'" class="mf-oem-panel">
				<div class="mf-oem-panel-head">
					<div>
						<h2>{{ selected.root && selected.root.name }}</h2>
						<p v-if="selected.navStack.length">{{ selected.navStack.map(function(n){ return n.title; }).join(' / ') }}</p>
					</div>
					<button type="button" v-if="selected.navStack.length" @click="goToCrumb({ step: 'browse', index: selected.navStack.length - 2 })">Назад</button>
					<button type="button" v-else @click="goToCrumb({ step: 'root' })">К брендам</button>
				</div>
				<div class="mf-oem-list">
					<button class="mf-oem-list-item" type="button" v-for="node in navNodes" :key="node.id" @click="selectNavNode(node)">
						<strong>{{ node.title }}</strong>
						<span>{{ navSubtitle(node) }}</span>
					</button>
				</div>
				<div class="mf-oem-empty" v-if="!loading && !navNodes.length">В этой папке пока нет подразделов.</div>
			</section>

			<section v-if="step === 'variant'" class="mf-oem-panel">
				<div class="mf-oem-panel-head">
					<div>
						<h2>Выберите модификацию</h2>
						<p>{{ selected.navNode && selected.navNode.title }}</p>
					</div>
					<button type="button" @click="goToCrumb({ step: 'browse', index: selected.navStack.length - 1 })">Назад</button>
				</div>
				<div class="mf-oem-list">
					<button class="mf-oem-list-item" type="button" v-for="variant in variants" :key="variant.id" @click="selectVariant(variant)">
						<strong>{{ variantTitle(variant) }}</strong>
						<span>{{ variantSubtitle(variant) }}</span>
					</button>
				</div>
			</section>

			<section v-if="step === 'assembly'" class="mf-oem-panel">
				<div class="mf-oem-panel-head">
					<div>
						<h2>Выберите узел</h2>
						<p>{{ selected.variant && variantTitle(selected.variant) }}</p>
					</div>
					<button type="button" @click="goToCrumb({ step: 'variant' })">Назад</button>
				</div>
				<input class="mf-oem-search" type="search" v-model.trim="filters.assembly" @input="debouncedLoadAssemblies" placeholder="Поиск узла">
				<div class="mf-oem-assembly-grid">
					<button class="mf-oem-assembly-card" type="button" v-for="assembly in assemblies" :key="assembly.id" @click="selectAssembly(assembly)">
						<span class="mf-oem-assembly-media">
							<img v-if="assemblyImageUrl(assembly)" :src="assemblyImageUrl(assembly)" :alt="assembly.title">
							<span v-else class="mf-oem-assembly-placeholder">Схема</span>
						</span>
						<span class="mf-oem-assembly-body">
							<strong>{{ assembly.title }}</strong>
							<small>{{ assembly.part_count }} деталей</small>
						</span>
					</button>
				</div>
			</section>

			<section v-if="step === 'diagram'" class="mf-oem-diagram-panel">
				<div class="mf-oem-panel-head">
					<div>
						<h2>{{ selected.assembly && selected.assembly.title }}</h2>
						<p>{{ selected.variant && variantTitle(selected.variant) }}</p>
					</div>
					<button type="button" @click="goToCrumb({ step: 'assembly' })">К узлам</button>
				</div>

				<div class="mf-oem-diagram-layout" v-if="diagramPayload">
					<div class="mf-oem-diagram-stage">
						<div class="mf-oem-image-scroll">
							<div class="mf-oem-image-wrap" :style="diagramWrapStyle">
								<img v-if="diagramImageUrl" :src="diagramImageUrl" :alt="selected.assembly.title" @load="onDiagramImageLoad">
								<button
									v-for="hotspot in diagramPayload.hotspots"
									:key="hotspot.id"
									class="mf-oem-hotspot"
									:class="{ '-active': activeAssemblyPartId === hotspot.assembly_part_id }"
									type="button"
									:style="hotspotStyle(hotspot)"
									:title="hotspot.ref"
									@click="focusPart(hotspot.assembly_part_id)"
								>
									{{ hotspot.ref || '' }}
								</button>
							</div>
						</div>
					</div>

					<div class="mf-oem-parts">
						<div class="mf-oem-parts-head">
							<strong>Детали</strong>
							<span>{{ diagramPayload.parts.length }}</span>
						</div>
						<div class="mf-oem-part-row" v-for="part in diagramPayload.parts" :key="part.assembly_part_id" :class="{ '-active': activeAssemblyPartId === part.assembly_part_id }" :data-part-id="part.assembly_part_id">
							<div class="mf-oem-ref">{{ part.ref || '-' }}</div>
							<div class="mf-oem-part-main">
								<strong>{{ part.name || 'Без названия' }}</strong>
								<span>{{ part.part_number }}</span>
							</div>
							<div class="mf-oem-part-meta" v-if="part.quantity">
								<span>Кол-во: {{ formatQuantity(part.quantity) }}</span>
							</div>
						</div>
					</div>
				</div>
			</section>

			<div class="mf-oem-loading -inline" v-if="loading">Загрузка...</div>
		</div>
	</div>
</div>

<?php require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php'); ?>
