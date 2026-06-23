<?php
define('HIDE_SIDEBAR', true);
require($_SERVER['DOCUMENT_ROOT'].'/bitrix/header.php');
$APPLICATION->SetTitle('Каталог схем запчастей');
$APPLICATION->SetPageProperty('title', 'Каталог схем запчастей Motor Force');
$APPLICATION->SetPageProperty('description', 'OEM каталог схем запчастей для мототехники: выбор типа техники, бренда, года, модели, узла и деталей по схеме.');
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
			<div class="mf-oem-step" :class="{ '-active': step === 'vehicle', '-done': selected.vehicleType }">
				<span>1</span>
				<div>
					<strong>Тип техники</strong>
					<small>{{ selected.vehicleType ? selected.vehicleType.name : 'Не выбран' }}</small>
				</div>
			</div>
			<div class="mf-oem-step" :class="{ '-active': step === 'brand', '-done': selected.brand }">
				<span>2</span>
				<div>
					<strong>Бренд</strong>
					<small>{{ selected.brand ? selected.brand.name : 'Не выбран' }}</small>
				</div>
			</div>
			<div class="mf-oem-step" :class="{ '-active': step === 'year', '-done': selected.year }">
				<span>3</span>
				<div>
					<strong>Год</strong>
					<small>{{ selected.year ? selected.year.year : 'Не выбран' }}</small>
				</div>
			</div>
			<div class="mf-oem-step" :class="{ '-active': step === 'model', '-done': selected.model }">
				<span>4</span>
				<div>
					<strong>Модель</strong>
					<small>{{ selected.model ? selected.model.name : 'Не выбрана' }}</small>
				</div>
			</div>
			<div class="mf-oem-step" :class="{ '-active': step === 'variant', '-done': selected.variant }">
				<span>5</span>
				<div>
					<strong>Вариант</strong>
					<small>{{ selected.variant ? variantTitle(selected.variant) : 'Не выбран' }}</small>
				</div>
			</div>
			<div class="mf-oem-step" :class="{ '-active': step === 'assembly', '-done': selected.assembly }">
				<span>6</span>
				<div>
					<strong>Узел</strong>
					<small>{{ selected.assembly ? selected.assembly.title : 'Не выбран' }}</small>
				</div>
			</div>
		</div>

		<div class="mf-oem-content">
			<div class="mf-oem-breadcrumbs" v-if="breadcrumbs.length">
				<button type="button" v-for="crumb in breadcrumbs" :key="crumb.step" @click="goToStep(crumb.step)">
					{{ crumb.label }}
				</button>
			</div>

			<section v-if="step === 'vehicle'" class="mf-oem-panel">
				<div class="mf-oem-panel-head">
					<h2>Выберите тип техники</h2>
					<p>Начните с выбора категории техники, затем перейдите к бренду, году, модели и схеме узла.</p>
				</div>
				<div class="mf-oem-grid">
					<button class="mf-oem-card" type="button" v-for="item in vehicleTypes" :key="item.id" @click="selectVehicleType(item)">
						<strong>{{ item.name }}</strong>
						<span>{{ vehicleTypeLabel(item.code) }}</span>
					</button>
				</div>
			</section>

			<section v-if="step === 'brand'" class="mf-oem-panel">
				<div class="mf-oem-panel-head">
					<h2>Выберите бренд</h2>
					<button type="button" @click="goToStep('vehicle')">Назад</button>
				</div>
				<div class="mf-oem-grid">
					<button class="mf-oem-card" type="button" v-for="brand in brands" :key="brand.id" @click="selectBrand(brand)">
						<strong>{{ brand.name }}</strong>
						<span>{{ brand.model_count }} моделей</span>
					</button>
				</div>
				<div class="mf-oem-empty" v-if="!loading && !brands.length">Для выбранного типа техники пока нет импортированных брендов.</div>
			</section>

			<section v-if="step === 'year'" class="mf-oem-panel">
				<div class="mf-oem-panel-head">
					<div>
						<h2>Выберите год</h2>
						<p>{{ selected.brand && selected.brand.name }}</p>
					</div>
					<button type="button" @click="goToStep('brand')">Назад</button>
				</div>
				<div class="mf-oem-grid">
					<button class="mf-oem-card" type="button" v-for="year in years" :key="year.year" @click="selectYear(year)">
						<strong>{{ year.year }}</strong>
						<span>{{ year.model_count }} моделей</span>
					</button>
				</div>
				<div class="mf-oem-empty" v-if="!loading && !years.length">Для выбранного бренда пока нет импортированных годов.</div>
			</section>

			<section v-if="step === 'model'" class="mf-oem-panel">
				<div class="mf-oem-panel-head">
					<div>
						<h2>Выберите модель</h2>
						<p>{{ selected.brand && selected.brand.name }} · {{ selected.year && selected.year.year }}</p>
					</div>
					<button type="button" @click="goToStep('year')">Назад</button>
				</div>
				<input class="mf-oem-search" type="search" v-model.trim="filters.model" @input="debouncedLoadModels" placeholder="Поиск модели">
				<div class="mf-oem-list">
					<button class="mf-oem-list-item" type="button" v-for="model in models" :key="model.id" @click="selectModel(model)">
						<strong>{{ model.name }}</strong>
						<span>{{ model.variant_count }} вариантов</span>
					</button>
				</div>
			</section>

			<section v-if="step === 'variant'" class="mf-oem-panel">
				<div class="mf-oem-panel-head">
					<div>
						<h2>Выберите вариант</h2>
						<p>{{ selected.year && selected.year.year }} · {{ selected.model && selected.model.name }}</p>
					</div>
					<button type="button" @click="goToStep('model')">Назад</button>
				</div>
				<div class="mf-oem-list">
					<button class="mf-oem-list-item" type="button" v-for="variant in variants" :key="variant.id" @click="selectVariant(variant)">
						<strong>{{ variantTitle(variant) }}</strong>
						<span>{{ variant.region || variant.variant_section || 'Без региона' }}</span>
					</button>
				</div>
			</section>

			<section v-if="step === 'assembly'" class="mf-oem-panel">
				<div class="mf-oem-panel-head">
					<div>
						<h2>Выберите узел</h2>
						<p>{{ selected.variant && variantTitle(selected.variant) }}</p>
					</div>
					<button type="button" @click="goToStep('variant')">Назад</button>
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
					<button type="button" @click="goToStep('assembly')">К узлам</button>
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
									:title="hotspot.ref || hotspot.source_items_list_id"
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
							<div class="mf-oem-part-meta" v-if="part.quantity || part.product_url">
								<span>Кол-во: {{ formatQuantity(part.quantity) }}</span>
								<a v-if="part.product_url" :href="part.product_url">Товар</a>
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
