(function () {
	'use strict';

	var root = document.getElementById('mfOemCatalog');
	if (!root || !window.Vue) {
		return;
	}

	var apiBase = root.getAttribute('data-api-base') || '/api/oem';
	var assetsBase = root.getAttribute('data-assets-base') || '/oem-assets';

	function apiGet(path, params) {
		var url = new URL(apiBase + path, window.location.origin);
		Object.keys(params || {}).forEach(function (key) {
			if (params[key] !== null && params[key] !== undefined && params[key] !== '') {
				url.searchParams.set(key, params[key]);
			}
		});
		return fetch(url.toString(), { credentials: 'same-origin' }).then(function (response) {
			if (!response.ok) {
				throw new Error('HTTP ' + response.status + ' для ' + url.pathname);
			}
			return response.json();
		}).then(function (payload) {
			return payload.data;
		});
	}

	function debounce(fn, delay) {
		var timer = null;
		return function () {
			var args = arguments;
			clearTimeout(timer);
			timer = setTimeout(function () {
				fn.apply(null, args);
			}, delay || 250);
		};
	}

	window.Vue.createApp({
		data: function () {
			return {
				step: 'vehicle',
				bootLoading: true,
				loading: false,
				error: '',
				vehicleTypes: [],
				brands: [],
				years: [],
				models: [],
				variants: [],
				assemblies: [],
				diagramPayload: null,
				selected: {
					vehicleType: null,
					brand: null,
					year: null,
					model: null,
					variant: null,
					assembly: null
				},
				filters: {
					model: '',
					assembly: ''
				},
				activeAssemblyPartId: null,
				diagramNatural: {
					width: 0,
					height: 0
				}
			};
		},
		computed: {
			breadcrumbs: function () {
				var items = [];
				if (this.selected.vehicleType) {
					items.push({ step: 'vehicle', label: this.selected.vehicleType.name });
				}
				if (this.selected.brand) {
					items.push({ step: 'brand', label: this.selected.brand.name });
				}
				if (this.selected.year) {
					items.push({ step: 'year', label: String(this.selected.year.year) });
				}
				if (this.selected.model) {
					items.push({ step: 'model', label: this.selected.model.name });
				}
				if (this.selected.variant) {
					items.push({ step: 'variant', label: this.variantTitle(this.selected.variant) });
				}
				if (this.selected.assembly) {
					items.push({ step: 'assembly', label: this.selected.assembly.title });
				}
				return items;
			},
			diagramImageUrl: function () {
				var diagram = this.diagramPayload && this.diagramPayload.diagram;
				if (!diagram) {
					return '';
				}
				if (diagram.local_path) {
					return this.assetUrl(diagram.local_path);
				}
				return diagram.public_url || diagram.original_url || '';
			},
			diagramWrapStyle: function () {
				var size = this.diagramBaseSize();
				return {
					aspectRatio: size.width + ' / ' + size.height
				};
			}
		},
		created: function () {
			this.debouncedLoadModels = debounce(this.loadModels.bind(this), 250);
			this.debouncedLoadAssemblies = debounce(this.loadAssemblies.bind(this), 250);
			this.loadVehicleTypes();
		},
		methods: {
			setLoading: function (value) {
				this.loading = value;
				if (value) {
					this.error = '';
				}
			},
			run: function (promise) {
				var self = this;
				this.setLoading(true);
				return promise.catch(function (error) {
					self.error = error.message || String(error);
					throw error;
				}).finally(function () {
					self.loading = false;
					self.bootLoading = false;
				});
			},
			loadVehicleTypes: function () {
				var self = this;
				return this.run(apiGet('/vehicle-types')).then(function (items) {
					self.vehicleTypes = items;
				});
			},
			loadBrands: function () {
				var self = this;
				return this.run(apiGet('/brands', {
					vehicle_type: this.selected.vehicleType && this.selected.vehicleType.code
				})).then(function (items) {
					self.brands = items;
				});
			},
			loadYears: function () {
				if (!this.selected.vehicleType || !this.selected.brand) {
					return Promise.resolve();
				}
				var self = this;
				return this.run(apiGet('/years', {
					vehicle_type: this.selected.vehicleType.code,
					brand_id: this.selected.brand.id
				})).then(function (items) {
					self.years = items;
				});
			},
			loadModels: function () {
				if (!this.selected.vehicleType || !this.selected.brand || !this.selected.year) {
					return Promise.resolve();
				}
				var self = this;
				return this.run(apiGet('/models', {
					vehicle_type: this.selected.vehicleType.code,
					brand_id: this.selected.brand.id,
					year: this.selected.year.year,
					q: this.filters.model
				})).then(function (items) {
					self.models = items;
				});
			},
			loadVariants: function () {
				if (!this.selected.model) {
					return Promise.resolve();
				}
				var self = this;
				return this.run(apiGet('/variants', {
					model_id: this.selected.model.id,
					year: this.selected.year && this.selected.year.year
				})).then(function (items) {
					self.variants = items;
				});
			},
			loadAssemblies: function () {
				if (!this.selected.variant) {
					return Promise.resolve();
				}
				var self = this;
				return this.run(apiGet('/assemblies', {
					variant_id: this.selected.variant.id,
					q: this.filters.assembly
				})).then(function (items) {
					self.assemblies = items;
				});
			},
			loadDiagram: function () {
				if (!this.selected.assembly) {
					return Promise.resolve();
				}
				var self = this;
				return this.run(apiGet('/diagrams/' + this.selected.assembly.id)).then(function (payload) {
					self.diagramPayload = payload;
					self.activeAssemblyPartId = null;
					self.diagramNatural = { width: 0, height: 0 };
				});
			},
			selectVehicleType: function (item) {
				this.selected.vehicleType = item;
				this.selected.brand = null;
				this.selected.year = null;
				this.selected.model = null;
				this.selected.variant = null;
				this.selected.assembly = null;
				this.brands = [];
				this.years = [];
				this.models = [];
				this.variants = [];
				this.assemblies = [];
				this.diagramPayload = null;
				this.step = 'brand';
				this.loadBrands();
			},
			selectBrand: function (brand) {
				this.selected.brand = brand;
				this.selected.year = null;
				this.selected.model = null;
				this.selected.variant = null;
				this.selected.assembly = null;
				this.filters.model = '';
				this.years = [];
				this.models = [];
				this.variants = [];
				this.assemblies = [];
				this.diagramPayload = null;
				this.step = 'year';
				this.loadYears();
			},
			selectYear: function (year) {
				this.selected.year = year;
				this.selected.model = null;
				this.selected.variant = null;
				this.selected.assembly = null;
				this.filters.model = '';
				this.models = [];
				this.variants = [];
				this.assemblies = [];
				this.diagramPayload = null;
				this.step = 'model';
				this.loadModels();
			},
			selectModel: function (model) {
				this.selected.model = model;
				this.selected.variant = null;
				this.selected.assembly = null;
				this.variants = [];
				this.assemblies = [];
				this.diagramPayload = null;
				this.step = 'variant';
				this.loadVariants();
			},
			selectVariant: function (variant) {
				this.selected.variant = variant;
				this.selected.assembly = null;
				this.filters.assembly = '';
				this.assemblies = [];
				this.diagramPayload = null;
				this.step = 'assembly';
				this.loadAssemblies();
			},
			selectAssembly: function (assembly) {
				this.selected.assembly = assembly;
				this.step = 'diagram';
				this.loadDiagram();
			},
			goToStep: function (step) {
				if (step === 'vehicle') {
					this.selected.vehicleType = null;
					this.selected.brand = null;
					this.selected.year = null;
					this.selected.model = null;
					this.selected.variant = null;
					this.selected.assembly = null;
					this.brands = [];
					this.years = [];
					this.models = [];
					this.variants = [];
					this.assemblies = [];
					this.diagramPayload = null;
					this.filters.model = '';
					this.filters.assembly = '';
				}
				if (step === 'brand') {
					this.selected.brand = null;
					this.selected.year = null;
					this.selected.model = null;
					this.selected.variant = null;
					this.selected.assembly = null;
					this.years = [];
					this.models = [];
					this.variants = [];
					this.assemblies = [];
					this.diagramPayload = null;
					this.filters.model = '';
					this.filters.assembly = '';
					if (this.selected.vehicleType) {
						this.loadBrands();
					}
				}
				if (step === 'year') {
					this.selected.year = null;
					this.selected.model = null;
					this.selected.variant = null;
					this.selected.assembly = null;
					this.models = [];
					this.variants = [];
					this.assemblies = [];
					this.diagramPayload = null;
					this.filters.model = '';
					this.filters.assembly = '';
					if (this.selected.vehicleType && this.selected.brand) {
						this.loadYears();
					}
				}
				if (step === 'model') {
					this.selected.model = null;
					this.selected.variant = null;
					this.selected.assembly = null;
					this.variants = [];
					this.assemblies = [];
					this.diagramPayload = null;
					this.filters.model = '';
					this.filters.assembly = '';
					if (this.selected.vehicleType && this.selected.brand && this.selected.year) {
						this.loadModels();
					}
				}
				if (step === 'variant') {
					this.selected.variant = null;
					this.selected.assembly = null;
					this.assemblies = [];
					this.diagramPayload = null;
					this.filters.assembly = '';
					if (this.selected.model) {
						this.loadVariants();
					}
				}
				if (step === 'assembly') {
					this.selected.assembly = null;
					this.diagramPayload = null;
					if (this.selected.variant) {
						this.loadAssemblies();
					}
				}
				this.step = step;
			},
			reloadCurrentStep: function () {
				if (this.step === 'vehicle') {
					return this.loadVehicleTypes();
				}
				if (this.step === 'brand') {
					return this.loadBrands();
				}
				if (this.step === 'year') {
					return this.loadYears();
				}
				if (this.step === 'model') {
					return this.loadModels();
				}
				if (this.step === 'variant') {
					return this.loadVariants();
				}
				if (this.step === 'assembly') {
					return this.loadAssemblies();
				}
				if (this.step === 'diagram') {
					return this.loadDiagram();
				}
			},
			vehicleTypeLabel: function (code) {
				var labels = {
					motorcycle: 'Мотоциклы',
					atv: 'Квадроциклы',
					ssv: 'Side-by-side',
					snowmobile: 'Снегоходы',
					jetski: 'Гидроциклы',
					outboard: 'Лодочные моторы'
				};
				return labels[code] || code;
			},
			variantTitle: function (variant) {
				var parts = [];
				if (variant.year_from) {
					parts.push(variant.year_from === variant.year_to || !variant.year_to ? variant.year_from : variant.year_from + '-' + variant.year_to);
				}
				if (variant.market_name) {
					parts.push(variant.market_name);
				}
				if (variant.model_code) {
					parts.push(variant.model_code);
				}
				if (variant.color_code) {
					parts.push(variant.color_code);
				}
				return parts.join(' · ') || variant.source_designation || ('Вариант #' + variant.id);
			},
			variantSubtitle: function (variant) {
				if (!variant) {
					return '';
				}
				var section = String(variant.variant_section || '').toLowerCase();
				if (section === 'chassis') {
					return 'Шасси';
				}
				if (section === 'engine') {
					return 'Двигатель';
				}
				if (variant.source_designation) {
					return String(variant.source_designation);
				}
				return '';
			},
			assetUrl: function (path) {
				if (!path) {
					return '';
				}
				return assetsBase.replace(/\/$/, '') + '/' + String(path).replace(/^\//, '');
			},
			assemblyImageUrl: function (assembly) {
				if (!assembly) {
					return '';
				}
				if (assembly.local_path) {
					return this.assetUrl(assembly.local_path);
				}
				return assembly.public_url || assembly.original_url || '';
			},
			formatQuantity: function (quantity) {
				var value = Number(quantity);
				if (!Number.isFinite(value)) {
					return quantity || '-';
				}
				return Number.isInteger(value) ? String(value) : String(value).replace(/0+$/, '').replace(/\.$/, '');
			},
			diagramBaseSize: function () {
				var diagram = this.diagramPayload && this.diagramPayload.diagram;
				var width = Number(diagram && diagram.width) || this.diagramNatural.width || 900;
				var height = Number(diagram && diagram.height) || this.diagramNatural.height || 500;
				return {
					width: Math.max(width, 1),
					height: Math.max(height, 1)
				};
			},
			hotspotBounds: function () {
				var size = this.diagramBaseSize();
				var maxRight = size.width;
				var maxBottom = size.height;
				var hotspots = (this.diagramPayload && this.diagramPayload.hotspots) || [];
				hotspots.forEach(function (hotspot) {
					maxRight = Math.max(maxRight, Number(hotspot.x || 0) + Number(hotspot.width || 18));
					maxBottom = Math.max(maxBottom, Number(hotspot.y || 0) + Number(hotspot.height || 18));
				});
				return {
					xScale: size.width / Math.max(maxRight, 1),
					yScale: size.height / Math.max(maxBottom, 1),
					width: size.width,
					height: size.height
				};
			},
			hotspotStyle: function (hotspot) {
				var bounds = this.hotspotBounds();
				var x = Number(hotspot.x || 0) * bounds.xScale;
				var y = Number(hotspot.y || 0) * bounds.yScale;
				var width = Math.max(Number(hotspot.width || 18) * bounds.xScale, 18);
				var height = Math.max(Number(hotspot.height || 18) * bounds.yScale, 18);
				var left = x / bounds.width * 100;
				var top = y / bounds.height * 100;
				var widthPercent = width / bounds.width * 100;
				var heightPercent = height / bounds.height * 100;
				return {
					left: Math.max(0, Math.min(left, 100 - widthPercent)) + '%',
					top: Math.max(0, Math.min(top, 100 - heightPercent)) + '%',
					width: widthPercent + '%',
					height: heightPercent + '%'
				};
			},
			focusPart: function (assemblyPartId) {
				if (!assemblyPartId) {
					return;
				}
				this.activeAssemblyPartId = assemblyPartId;
				this.$nextTick(function () {
					var row = root.querySelector('[data-part-id="' + assemblyPartId + '"]');
					if (row) {
						row.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
					}
				});
			},
			onDiagramImageLoad: function (event) {
				this.diagramNatural.width = event.target.naturalWidth;
				this.diagramNatural.height = event.target.naturalHeight;
			}
		}
	}).mount(root);
})();
