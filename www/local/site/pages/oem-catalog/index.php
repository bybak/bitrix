<?php
define('HIDE_SIDEBAR', true);
require($_SERVER['DOCUMENT_ROOT'].'/bitrix/header.php');
$APPLICATION->SetTitle('Каталог схем запчастей');
$APPLICATION->SetPageProperty('title', 'Каталог схем запчастей Motor Force');
$APPLICATION->SetPageProperty('description', 'OEM каталог схем запчастей: Husqvarna, KTM, Lynx, BRP, Yamaha — навигация по дереву каталога.');
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
			<div class="mf-oem-step" :class="{ '-active': step === 'brand', '-done': selected.brand }">
				<span>1</span>
				<div>
					<strong>Бренд</strong>
					<small>{{ selected.brand ? selected.brand.name : 'Не выбран' }}</small>
				</div>
			</div>
			<div class="mf-oem-step" :class="{ '-active': step === 'region', '-done': selected.root && brandNeedsRegionStep }" v-if="brandNeedsRegionStep || step === 'region'">
				<span>2</span>
				<div>
					<strong>Регион</strong>
					<small>{{ selected.root ? selected.root.name : 'Не выбран' }}</small>
				</div>
			</div>
			<div class="mf-oem-step" :class="{ '-active': step === 'browse', '-done': selected.navStack.length }">
				<span>{{ brandNeedsRegionStep ? 3 : 2 }}</span>
				<div>
					<strong>Каталог</strong>
					<small>{{ selected.navNode ? selected.navNode.title : 'Папки' }}</small>
				</div>
			</div>
			<div class="mf-oem-step" :class="{ '-active': step === 'variant' || step === 'assembly', '-done': selected.variant }">
				<span>{{ brandNeedsRegionStep ? 4 : 3 }}</span>
				<div>
					<strong>Модификация</strong>
					<small>{{ selected.variant ? variantTitle(selected.variant) : 'Не выбрана' }}</small>
				</div>
			</div>
			<div class="mf-oem-step" :class="{ '-active': step === 'assembly' || step === 'diagram', '-done': selected.assembly }">
				<span>{{ brandNeedsRegionStep ? 5 : 4 }}</span>
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

			<section v-if="step === 'brand'" class="mf-oem-panel">
				<div class="mf-oem-panel-head">
					<h2>Каталог схем</h2>
				</div>

				<label class="mf-oem-search-label">
					<span>Поиск по оригинальному номеру запчасти</span>
					<input
						class="mf-oem-search"
						type="search"
						v-model.trim="filters.partNumber"
						@input="onPartNumberInput"
						placeholder="Например, 54830015100"
						autocomplete="off"
					>
				</label>

				<div class="mf-oem-part-search-meta" v-if="partSearchLoading">Ищем по каталогу...</div>
				<div class="mf-oem-part-search-meta" v-else-if="partUsages">
					<span>Найдено узлов: {{ partUsages.total_count || partUsages.match_count }}</span>
					<span v-if="partUsages.truncated">Показаны первые {{ partUsages.match_count }} из {{ partUsages.total_count }}</span>
				</div>

				<div class="mf-oem-part-usages" v-if="partUsages && partUsages.groups.length">
					<div class="mf-oem-usage-brand" v-for="brandGroup in partUsages.groups" :key="brandGroup.root_arib">
						<button
							type="button"
							class="mf-oem-usage-toggle"
							:class="{ '-open': isUsageExpanded(usageKey('brand', brandGroup.root_arib)) }"
							@click="toggleUsageExpanded(usageKey('brand', brandGroup.root_arib))"
						>
							<span class="mf-oem-usage-chevron" aria-hidden="true"></span>
							<span class="mf-oem-usage-toggle-main">
								<strong>{{ brandGroup.root_name }}</strong>
								<small>{{ brandGroup.families.length }} {{ usagePlural(brandGroup.families.length, 'семейство', 'семейства', 'семейств') }} · {{ usageCountAssembliesInBrand(brandGroup) }} {{ usagePlural(usageCountAssembliesInBrand(brandGroup), 'узел', 'узла', 'узлов') }}</small>
							</span>
						</button>
						<div class="mf-oem-usage-body" v-if="isUsageExpanded(usageKey('brand', brandGroup.root_arib))">
							<div class="mf-oem-usage-family" v-for="family in brandGroup.families" :key="brandGroup.root_arib + ':' + family.key">
								<button
									type="button"
									class="mf-oem-usage-toggle -nested"
									:class="{ '-open': isUsageExpanded(usageKey('family', brandGroup.root_arib, family.key)) }"
									@click="toggleUsageExpanded(usageKey('family', brandGroup.root_arib, family.key))"
								>
									<span class="mf-oem-usage-chevron" aria-hidden="true"></span>
									<span class="mf-oem-usage-toggle-main">
										<strong>{{ family.label }}</strong>
										<small>{{ family.models.length }} {{ usagePlural(family.models.length, 'модель', 'модели', 'моделей') }} · {{ usageCountAssembliesInFamily(family) }} {{ usagePlural(usageCountAssembliesInFamily(family), 'узел', 'узла', 'узлов') }}</small>
									</span>
								</button>
								<div class="mf-oem-usage-body" v-if="isUsageExpanded(usageKey('family', brandGroup.root_arib, family.key))">
									<div class="mf-oem-usage-model" v-for="model in family.models" :key="brandGroup.root_arib + ':' + family.key + ':' + model.key">
										<button
											type="button"
											class="mf-oem-usage-toggle -nested"
											:class="{ '-open': isUsageExpanded(usageKey('model', brandGroup.root_arib, family.key, model.key)) }"
											@click="toggleUsageExpanded(usageKey('model', brandGroup.root_arib, family.key, model.key))"
										>
											<span class="mf-oem-usage-chevron" aria-hidden="true"></span>
											<span class="mf-oem-usage-toggle-main">
												<strong>{{ model.label }}</strong>
												<small>{{ model.variants.length }} {{ usagePlural(model.variants.length, 'модификация', 'модификации', 'модификаций') }} · {{ usageCountAssembliesInModel(model) }} {{ usagePlural(usageCountAssembliesInModel(model), 'узел', 'узла', 'узлов') }}</small>
											</span>
										</button>
										<div class="mf-oem-usage-body" v-if="isUsageExpanded(usageKey('model', brandGroup.root_arib, family.key, model.key))">
											<div class="mf-oem-usage-variant" v-for="variant in model.variants" :key="variant.id">
												<button
													type="button"
													class="mf-oem-usage-toggle -nested"
													:class="{ '-open': isUsageExpanded(usageKey('variant', variant.id)) }"
													@click="toggleUsageExpanded(usageKey('variant', variant.id))"
												>
													<span class="mf-oem-usage-chevron" aria-hidden="true"></span>
													<span class="mf-oem-usage-toggle-main">
														<strong>{{ variantTitle(variant) }}</strong>
														<small>{{ usageCountAssembliesInVariant(variant) }} {{ usagePlural(usageCountAssembliesInVariant(variant), 'узел', 'узла', 'узлов') }}</small>
													</span>
												</button>
												<div class="mf-oem-usage-body" v-if="isUsageExpanded(usageKey('variant', variant.id))">
													<div class="mf-oem-list">
														<button
															class="mf-oem-list-item"
															type="button"
															v-for="assembly in variant.assemblies"
															:key="assembly.id"
															@click="openPartUsage(brandGroup, variant, assembly)"
														>
															<strong>{{ assembly.title }}</strong>
															<span>{{ assemblyRefsLabel(assembly) }}</span>
														</button>
													</div>
												</div>
											</div>
										</div>
									</div>
								</div>
							</div>
						</div>
					</div>
				</div>

				<div class="mf-oem-empty" v-else-if="partUsages && !partUsages.groups.length">
					По номеру «{{ partUsages.query }}» ничего не найдено.
				</div>

				<template v-if="!partUsages || !partUsages.groups.length">
					<div class="mf-oem-panel-head mf-oem-panel-head -compact">
						<h2>Выберите бренд</h2>
					</div>
					<div class="mf-oem-grid">
						<button class="mf-oem-card" type="button" v-for="item in brands" :key="item.code" @click="selectBrand(item)">
							<strong>{{ item.name }}</strong>
							<span v-if="item.roots_count > 1">{{ item.roots_count }} региона</span>
							<span v-else>OEM каталог</span>
						</button>
					</div>
				</template>
			</section>

			<section v-if="step === 'region'" class="mf-oem-panel">
				<div class="mf-oem-panel-head">
					<div>
						<h2>{{ selected.brand && selected.brand.name }}</h2>
						<p>Выберите регион каталога</p>
					</div>
					<button type="button" @click="goToCrumb({ step: 'brand' })">К брендам</button>
				</div>
				<div class="mf-oem-grid">
					<button class="mf-oem-card" type="button" v-for="item in brandRoots" :key="item.arib_code" @click="selectRoot(item)">
						<strong>{{ item.name }}</strong>
						<span>{{ item.arib_code }}</span>
					</button>
				</div>
			</section>

			<section v-if="step === 'browse'" class="mf-oem-panel">
				<div class="mf-oem-panel-head">
					<div>
						<h2>{{ catalogBrowseTitle }}</h2>
						<p v-if="selected.navStack.length">{{ selected.navStack.map(function(n){ return n.title; }).join(' / ') }}</p>
					</div>
					<button type="button" v-if="selected.navStack.length" @click="goToCrumb({ step: 'browse', index: selected.navStack.length - 2 })">Назад</button>
					<button type="button" v-else-if="brandNeedsRegionStep" @click="goToCrumb({ step: 'region' })">К регионам</button>
					<button type="button" v-else @click="goToCrumb({ step: 'brand' })">К брендам</button>
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
				<div class="mf-oem-variant-grid">
					<button class="mf-oem-variant-card" type="button" v-for="variant in variants" :key="variant.id" @click="selectVariant(variant)">
						<span class="mf-oem-variant-media">
							<img v-if="variantThumbnailUrl(variant)" :src="variantThumbnailUrl(variant)" :alt="variantTitle(variant)">
							<span v-else class="mf-oem-variant-placeholder">{{ variant.source_designation || 'Мод.' }}</span>
						</span>
						<span class="mf-oem-variant-body">
							<strong>{{ variantTitle(variant) }}</strong>
							<small>{{ variantSubtitle(variant) }}</small>
						</span>
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
						<div
							ref="diagramViewport"
							class="mf-oem-image-scroll"
							:style="diagramViewportStyle"
						>
							<div class="mf-oem-diagram-zoom-controls">
								<button type="button" aria-label="Увеличить схему" @click="diagramZoomIn">+</button>
								<button type="button" aria-label="Уменьшить схему" :disabled="diagramZoomOutDisabled" @click="diagramZoomOut">−</button>
							</div>
							<div
								class="mf-oem-diagram-pan"
								:class="{ '-dragging': diagramPanDragging, '-zoomed': diagramZoom > 1 }"
								:style="diagramPanLayerStyle"
								@pointerdown="onDiagramPanPointerDown"
								@pointermove="onDiagramPanPointerMove"
								@pointerup="onDiagramPanPointerUp"
								@pointercancel="onDiagramPanPointerUp"
							>
								<div class="mf-oem-image-wrap" :style="diagramWrapStyle">
									<img
										ref="diagramImage"
										v-if="diagramImageUrl"
										:src="diagramImageUrl"
										:alt="selected.assembly.title"
										draggable="false"
										@load="onDiagramImageLoad"
										@dragstart.prevent
									>
									<button
										v-if="diagramImageReady"
										v-for="hotspot in diagramPayload.hotspots"
										:key="hotspot.id"
										class="mf-oem-hotspot"
										:class="{ '-active': isDiagramPartActive(hotspot.assembly_part_id) }"
										type="button"
										:style="hotspotStyle(hotspot)"
										:data-ref="hotspot.ref || null"
										:aria-label="hotspot.ref ? ('Позиция ' + hotspot.ref) : 'Позиция на схеме'"
										:data-part-id="hotspot.assembly_part_id"
										@click="focusPart(hotspot.assembly_part_id, { scrollTarget: 'part' })"
									></button>
								</div>
							</div>
						</div>
					</div>

					<div class="mf-oem-parts">
						<div class="mf-oem-parts-head">
							<strong>Детали</strong>
							<span>{{ diagramPayload.parts.length }}</span>
						</div>
						<div
							class="mf-oem-part-item"
							v-for="part in diagramPayload.parts"
							:key="part.assembly_part_id"
						>
							<div
								class="mf-oem-part-row"
								:class="{ '-active': isDiagramPartActive(part.assembly_part_id) }"
								:data-part-id="part.assembly_part_id"
								role="button"
								tabindex="0"
								@click="focusPart(part.assembly_part_id, { scrollTarget: 'diagram' })"
								@keydown.enter.prevent="focusPart(part.assembly_part_id, { scrollTarget: 'diagram' })"
								@keydown.space.prevent="focusPart(part.assembly_part_id, { scrollTarget: 'diagram' })"
							>
								<div class="mf-oem-ref">{{ part.ref || '-' }}</div>
								<div class="mf-oem-part-main">
									<strong>{{ part.name || 'Без названия' }}</strong>
									<span>{{ part.part_number }}</span>
								</div>
								<div class="mf-oem-part-actions">
									<button
										type="button"
										class="mf-oem-part-offers-btn"
										:class="{ '-open': partOffersOpenId === part.assembly_part_id }"
										@click.stop="togglePartOffers(part)"
									>
										{{ partOffersOpenId === part.assembly_part_id ? 'Скрыть' : 'Наличие' }}
									</button>
								</div>
								<div class="mf-oem-part-meta" v-if="part.quantity">
									<span>Кол-во: {{ formatQuantity(part.quantity) }}</span>
								</div>
							</div>
							<div
								v-if="partOffersOpenId === part.assembly_part_id"
								class="mf-oem-part-offers"
								@click.stop
							>
								<div class="mf-oem-part-offers__loading" v-if="partOffersEntry(part.assembly_part_id).loading">
									Загружаем наличие и цены...
								</div>
								<div class="mf-oem-part-offers__error" v-else-if="partOffersEntry(part.assembly_part_id).error">
									{{ partOffersEntry(part.assembly_part_id).error }}
								</div>
								<div class="mf-oem-part-offers__empty" v-else-if="!partOffersEntry(part.assembly_part_id).products.length">
									{{ partOffersEntry(part.assembly_part_id).emptyMessage || ('По артикулу «' + (part.part_number || '—') + '» предложений не найдено.') }}
								</div>
								<div
									class="mf-oem-part-offers__product"
									v-for="product in partOffersEntry(part.assembly_part_id).products"
									:key="product.id"
								>
									<div class="mf-oem-part-offers__head">
										<div class="mf-oem-part-offers__title">
											<strong>{{ product.name }}</strong>
											<span v-if="product.brand">{{ product.brand }}</span>
										</div>
										<a class="mf-oem-part-offers__link" :href="product.url" target="_blank" rel="noopener">
											Открыть товар
										</a>
									</div>
									<div class="mf-oem-part-offers__table" v-html="product.html"></div>
									<div
										class="mf-oem-part-offers__analogs"
										v-if="product.analogs && product.analogs.length"
									>
										<div class="mf-oem-part-offers__analogs-title">Аналоги того же бренда</div>
										<div
											class="mf-oem-part-offers__analog"
											v-for="analog in product.analogs"
											:key="analog.id"
										>
											<div class="mf-oem-part-offers__head">
												<div class="mf-oem-part-offers__title">
													<strong>{{ analog.name }}</strong>
													<span v-if="analog.brand">{{ analog.brand }}</span>
												</div>
												<a class="mf-oem-part-offers__link" :href="analog.url" target="_blank" rel="noopener">
													Открыть товар
												</a>
											</div>
											<div class="mf-oem-part-offers__table" v-html="analog.html"></div>
										</div>
									</div>
								</div>
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
