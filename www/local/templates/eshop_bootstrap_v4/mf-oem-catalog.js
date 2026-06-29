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
				step: 'root',
				bootLoading: true,
				loading: false,
				error: '',
				catalogRoots: [],
				navNodes: [],
				variants: [],
				assemblies: [],
				diagramPayload: null,
				selected: {
					root: null,
					navStack: [],
					navNode: null,
					variant: null,
					assembly: null
				},
				filters: {
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
				var items = [{ step: 'root', label: 'Каталог' }];
				if (this.selected.root) {
					items.push({ step: 'browse', index: -1, label: this.selected.root.name });
				}
				this.selected.navStack.forEach(function (node, index) {
					items.push({ step: 'browse', index: index, label: node.title });
				});
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
			},
			currentParentId: function () {
				if (this.selected.navStack.length) {
					return this.selected.navStack[this.selected.navStack.length - 1].id;
				}
				return null;
			}
		},
		created: function () {
			this.debouncedLoadAssemblies = debounce(this.loadAssemblies.bind(this), 250);
			this.loadRoots();
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
			loadRoots: function () {
				var self = this;
				return this.run(apiGet('/roots')).then(function (items) {
					self.catalogRoots = items;
				});
			},
			loadNav: function () {
				if (!this.selected.root) {
					return Promise.resolve();
				}
				var self = this;
				return this.run(apiGet('/nav', {
					root: this.selected.root.arib_code,
					parent_id: this.currentParentId
				})).then(function (items) {
					self.navNodes = items;
				});
			},
			loadVariantsForNav: function (navNodeId) {
				var self = this;
				return this.run(apiGet('/variants', { nav_node_id: navNodeId })).then(function (items) {
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
			selectRoot: function (item) {
				this.selected.root = item;
				this.selected.navStack = [];
				this.selected.navNode = null;
				this.selected.variant = null;
				this.selected.assembly = null;
				this.variants = [];
				this.assemblies = [];
				this.diagramPayload = null;
				this.step = 'browse';
				this.loadNav();
			},
			selectNavNode: function (node) {
				var self = this;
				this.selected.navStack.push(node);
				this.selected.navNode = node;
				this.selected.variant = null;
				this.selected.assembly = null;
				this.variants = [];
				this.assemblies = [];
				this.diagramPayload = null;

				if (Number(node.child_count) > 0) {
					this.step = 'browse';
					return this.loadNav();
				}
				if (Number(node.variant_count) > 0) {
					this.step = 'variant';
					return this.loadVariantsForNav(node.id).then(function () {
						if (self.variants.length === 1) {
							self.selectVariant(self.variants[0]);
						}
					});
				}
				this.step = 'browse';
				return this.loadNav();
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
			goToCrumb: function (crumb) {
				if (crumb.step === 'root') {
					this.selected.root = null;
					this.selected.navStack = [];
					this.selected.navNode = null;
					this.selected.variant = null;
					this.selected.assembly = null;
					this.navNodes = [];
					this.variants = [];
					this.assemblies = [];
					this.diagramPayload = null;
					this.step = 'root';
					return;
				}
				if (crumb.step === 'browse') {
					if (crumb.index < 0) {
						this.selected.navStack = [];
					} else {
						this.selected.navStack = this.selected.navStack.slice(0, crumb.index + 1);
					}
					this.selected.navNode = this.selected.navStack[this.selected.navStack.length - 1] || null;
					this.selected.variant = null;
					this.selected.assembly = null;
					this.variants = [];
					this.assemblies = [];
					this.diagramPayload = null;
					this.step = 'browse';
					this.loadNav();
					return;
				}
				if (crumb.step === 'variant') {
					this.selected.assembly = null;
					this.diagramPayload = null;
					this.step = 'assembly';
					this.loadAssemblies();
					return;
				}
				if (crumb.step === 'assembly') {
					this.step = 'diagram';
					this.loadDiagram();
				}
			},
			reloadCurrentStep: function () {
				if (this.step === 'root') {
					return this.loadRoots();
				}
				if (this.step === 'browse') {
					return this.loadNav();
				}
				if (this.step === 'variant' && this.selected.navNode) {
					return this.loadVariantsForNav(this.selected.navNode.id);
				}
				if (this.step === 'assembly') {
					return this.loadAssemblies();
				}
				if (this.step === 'diagram') {
					return this.loadDiagram();
				}
			},
			navSubtitle: function (node) {
				var parts = [];
				if (Number(node.child_count) > 0) {
					parts.push(node.child_count + ' папок');
				}
				if (Number(node.variant_count) > 0) {
					parts.push(node.variant_count + ' вариантов');
				}
				return parts.join(' · ') || 'Открыть';
			},
			variantTitle: function (variant) {
				var parts = [];
				if (variant.year_from) {
					parts.push(String(variant.year_from));
				}
				if (variant.source_designation) {
					parts.push(variant.source_designation);
				} else if (variant.model_name) {
					parts.push(variant.model_name);
				}
				if (variant.variant_section) {
					parts.push(variant.variant_section);
				}
				return parts.join(' · ') || ('Вариант #' + variant.id);
			},
			variantSubtitle: function (variant) {
				if (!variant) {
					return '';
				}
				return (variant.assembly_count || 0) + ' узлов';
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
