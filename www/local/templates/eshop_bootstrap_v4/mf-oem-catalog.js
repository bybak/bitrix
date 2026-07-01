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

	function readUrlState() {
		var params = new URLSearchParams(window.location.search);
		var navRaw = params.get('nav') || '';
		var navIds = navRaw.split(',').map(function (value) {
			return Number(value);
		}).filter(function (value) {
			return Number.isFinite(value) && value > 0;
		});
		return {
			root: (params.get('root') || '').trim(),
			nav: navIds,
			variant: Number(params.get('variant')) || 0,
			assembly: Number(params.get('assembly')) || 0,
			part: Number(params.get('part')) || 0,
			q: (params.get('q') || '').trim()
		};
	}

	function buildUrlSearch(state) {
		var params = new URLSearchParams();
		if (state.q) {
			params.set('q', state.q);
		}
		if (state.root) {
			params.set('root', state.root);
		}
		if (state.nav && state.nav.length) {
			params.set('nav', state.nav.join(','));
		}
		if (state.variant) {
			params.set('variant', String(state.variant));
		}
		if (state.assembly) {
			params.set('assembly', String(state.assembly));
		}
		if (state.part) {
			params.set('part', String(state.part));
		}
		var query = params.toString();
		return query;
	}

	function currentUrlQuery() {
		return window.location.search.replace(/^\?/, '');
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
					assembly: '',
					partNumber: ''
				},
				partUsages: null,
				partSearchLoading: false,
				partUsageExpanded: {},
				activeAssemblyPartId: null,
				diagramNatural: {
					width: 0,
					height: 0
				},
				diagramDisplay: {
					width: 0,
					height: 0
				},
				diagramZoom: 1,
				diagramPan: {
					x: 0,
					y: 0
				},
				diagramPanDragging: false,
				diagramPanPointerId: null,
				diagramPanStart: null,
				diagramPanDidDrag: false,
				diagramResizeObserver: null,
				urlSyncSuspended: false,
				pendingUrlPartId: null,
				partOffersOpenId: null,
				partOffersByPartId: {},
				onPopStateHandler: null
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
			diagramImageReady: function () {
				return this.diagramNatural.width > 0 && this.diagramNatural.height > 0;
			},
			diagramNaturalSize: function () {
				if (this.diagramNatural.width > 0 && this.diagramNatural.height > 0) {
					return {
						width: this.diagramNatural.width,
						height: this.diagramNatural.height
					};
				}
				var fromApi = this.diagramPayload && this.diagramPayload.image_size;
				if (fromApi && Number(fromApi.width) > 0 && Number(fromApi.height) > 0) {
					return {
						width: Number(fromApi.width),
						height: Number(fromApi.height)
					};
				}
				return { width: 0, height: 0 };
			},
			diagramCoordSpace: function () {
				var fromApi = this.diagramPayload && this.diagramPayload.coord_space;
				if (fromApi && Number(fromApi.width) > 0 && Number(fromApi.height) > 0) {
					return {
						width: Number(fromApi.width),
						height: Number(fromApi.height)
					};
				}
				return this.diagramNaturalSize;
			},
			diagramWrapStyle: function () {
				var size = this.diagramNaturalSize;
				if (!size.width || !size.height) {
					return {};
				}
				return {
					aspectRatio: size.width + ' / ' + size.height
				};
			},
			diagramViewportStyle: function () {
				var size = this.diagramNaturalSize;
				if (!size.width || !size.height) {
					return {};
				}
				return {
					aspectRatio: size.width + ' / ' + size.height
				};
			},
			diagramPanLayerStyle: function () {
				return {
					transform: 'translate(' + this.diagramPan.x + 'px, ' + this.diagramPan.y + 'px) scale(' + this.diagramZoom + ')',
					transformOrigin: '0 0'
				};
			},
			diagramZoomOutDisabled: function () {
				return this.diagramZoom <= 1.001;
			},
			currentParentId: function () {
				if (this.selected.navStack.length) {
					return this.selected.navStack[this.selected.navStack.length - 1].id;
				}
				return null;
			}
		},
		created: function () {
			var self = this;
			this.debouncedLoadAssemblies = debounce(this.loadAssemblies.bind(this), 250);
			this.debouncedSearchPartUsages = debounce(this.searchPartUsages.bind(this), 350);
			this.debouncedSyncDiagramDisplay = debounce(this.syncDiagramDisplaySize.bind(this), 50);
			this.debouncedSyncUrlFromState = debounce(this.syncUrlFromState.bind(this), 50);
			this.onPopStateHandler = function () {
				if (self.urlSyncSuspended) {
					return;
				}
				self.urlSyncSuspended = true;
				self.restoreFromUrl().finally(function () {
					self.urlSyncSuspended = false;
				});
			};
			window.addEventListener('popstate', this.onPopStateHandler);
			this.bindPartOffersUiHandlers();
			this.loadRoots().then(function () {
				return self.restoreFromUrl();
			}).catch(function (error) {
				self.error = error.message || String(error);
			}).finally(function () {
				self.bootLoading = false;
			});
		},
		beforeUnmount: function () {
			if (this.onPopStateHandler) {
				window.removeEventListener('popstate', this.onPopStateHandler);
			}
			this.teardownDiagramObserver();
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
				this.setLoading(true);
				return apiGet('/roots').then(function (items) {
					self.catalogRoots = items;
				}).catch(function (error) {
					self.error = error.message || String(error);
					throw error;
				}).finally(function () {
					self.loading = false;
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
				var pendingPart = this.pendingUrlPartId;
				this.setLoading(true);
				return apiGet('/diagrams/' + this.selected.assembly.id).then(function (payload) {
					self.teardownDiagramObserver();
					self.diagramPayload = payload;
					self.activeAssemblyPartId = null;
					self.diagramNatural = { width: 0, height: 0 };
					self.diagramDisplay = { width: 0, height: 0 };
					self.diagramZoom = 1;
					self.diagramPan = { x: 0, y: 0 };
					self.diagramPanDragging = false;
					self.diagramPanPointerId = null;
					self.diagramPanStart = null;
					self.diagramPanDidDrag = false;
					self.partOffersOpenId = null;
					self.partOffersByPartId = {};
					if (pendingPart) {
						self.pendingUrlPartId = pendingPart;
					}
				}).catch(function (error) {
					self.error = error.message || String(error);
					throw error;
				}).finally(function () {
					self.loading = false;
				});
			},
			buildAppUrlState: function () {
				var navIds = this.selected.navStack.map(function (node) {
					return Number(node.id);
				}).filter(function (id) {
					return id > 0;
				});
				return {
					q: this.step === 'root' && (this.filters.partNumber || '').trim() ? this.filters.partNumber.trim() : '',
					root: this.selected.root ? this.selected.root.arib_code : '',
					nav: navIds,
					variant: this.selected.variant ? Number(this.selected.variant.id) : 0,
					assembly: this.selected.assembly ? Number(this.selected.assembly.id) : 0,
					part: this.step === 'diagram' && this.activeAssemblyPartId ? Number(this.activeAssemblyPartId) : 0
				};
			},
			syncUrlFromState: function (replace) {
				if (this.urlSyncSuspended) {
					return;
				}
				var next = buildUrlSearch(this.buildAppUrlState());
				if (next === currentUrlQuery()) {
					return;
				}
				var url = window.location.pathname + (next ? '?' + next : '');
				if (replace) {
					window.history.replaceState(null, '', url);
				} else {
					window.history.pushState(null, '', url);
				}
			},
			findRootByArib: function (aribCode) {
				var code = String(aribCode || '').toUpperCase();
				for (var i = 0; i < this.catalogRoots.length; i += 1) {
					if (this.catalogRoots[i].arib_code === code) {
						return this.catalogRoots[i];
					}
				}
				return null;
			},
			restoreNavPath: function (navIds) {
				var self = this;
				self.selected.navStack = [];
				self.selected.navNode = null;
				if (!navIds || !navIds.length) {
					return Promise.resolve();
				}
				return navIds.reduce(function (chain, navId) {
					return chain.then(function () {
						return apiGet('/nav', {
							root: self.selected.root.arib_code,
							parent_id: self.currentParentId
						}).then(function (nodes) {
							var node = null;
							for (var i = 0; i < nodes.length; i += 1) {
								if (Number(nodes[i].id) === Number(navId)) {
									node = nodes[i];
									break;
								}
							}
							if (!node) {
								throw new Error('Папка каталога #' + navId + ' не найдена');
							}
							self.selected.navStack.push(node);
							self.selected.navNode = node;
						});
					});
				}, Promise.resolve());
			},
			restoreFromUrl: function () {
				var self = this;
				var url = readUrlState();
				if (!url.root && !url.q && !url.assembly && !url.variant) {
					self.syncUrlFromState(true);
					return Promise.resolve();
				}

				self.urlSyncSuspended = true;
				self.error = '';

				if (url.q) {
					self.step = 'root';
					self.filters.partNumber = url.q;
					return self.searchPartUsages().then(function () {
						self.syncUrlFromState(true);
					}).finally(function () {
						self.urlSyncSuspended = false;
					});
				}

				return self.restoreCatalogFromUrl(url).then(function () {
					self.syncUrlFromState(true);
				}).catch(function (error) {
					self.error = error.message || String(error);
				}).finally(function () {
					self.urlSyncSuspended = false;
				});
			},
			restoreCatalogFromUrl: function (url) {
				var self = this;
				var rootItem = self.findRootByArib(url.root);
				if (!rootItem) {
					return Promise.reject(new Error('Бренд "' + url.root + '" не найден'));
				}

				self.selected.root = rootItem;
				self.selected.navStack = [];
				self.selected.navNode = null;
				self.selected.variant = null;
				self.selected.assembly = null;
				self.variants = [];
				self.assemblies = [];
				self.diagramPayload = null;
				self.activeAssemblyPartId = null;

				if (url.assembly) {
					return self.restoreDiagramFromUrl(url, rootItem);
				}

				return self.restoreNavPath(url.nav).then(function () {
					if (url.variant) {
						return apiGet('/variants/' + url.variant).then(function (variant) {
							self.selected.variant = variant;
							self.step = 'assembly';
							return self.loadAssemblies();
						});
					}
					if (self.selected.navNode && Number(self.selected.navNode.variant_count) > 0) {
						self.step = 'variant';
						return self.loadVariantsForNav(self.selected.navNode.id);
					}
					self.step = 'browse';
					return self.loadNav();
				});
			},
			restoreDiagramFromUrl: function (url, rootItem) {
				var self = this;
				var variantId = url.variant;
				var pendingPart = url.part || null;

				return apiGet('/diagrams/' + url.assembly).then(function (payload) {
					if (!payload || !payload.assembly) {
						throw new Error('Узел #' + url.assembly + ' не найден');
					}
					var assembly = payload.assembly;
					if (String(assembly.root_arib || '').toUpperCase() !== String(rootItem.arib_code).toUpperCase()) {
						throw new Error('Узел не относится к выбранному бренду');
					}
					if (!variantId) {
						variantId = Number(assembly.variant_id);
					}
					return apiGet('/variants/' + variantId).then(function (variant) {
						var navChain = url.nav && url.nav.length
							? self.restoreNavPath(url.nav)
							: Promise.resolve();
						return navChain.then(function () {
							self.selected.variant = variant;
							self.selected.assembly = {
								id: assembly.id,
								title: assembly.title
							};
							self.pendingUrlPartId = pendingPart;
							self.step = 'diagram';
							return self.loadDiagram().then(function () {
								return self.applyPendingUrlPart();
							});
						});
					});
				});
			},
			applyPendingUrlPart: function () {
				if (!this.pendingUrlPartId || !this.diagramPayload) {
					return Promise.resolve();
				}
				var partId = Number(this.pendingUrlPartId);
				var parts = this.diagramPayload.parts || [];
				var exists = false;
				for (var i = 0; i < parts.length; i += 1) {
					if (Number(parts[i].assembly_part_id) === partId) {
						exists = true;
						break;
					}
				}
				this.pendingUrlPartId = null;
				if (!exists) {
					return Promise.resolve();
				}
				var self = this;
				return new Promise(function (resolve) {
					self.$nextTick(function () {
						self.focusPart(partId, { skipUrl: true });
						resolve();
					});
				});
			},
			searchPartUsages: function () {
				var query = (this.filters.partNumber || '').trim();
				if (query.length < 2) {
					this.partUsages = null;
					this.partUsageExpanded = {};
					this.partSearchLoading = false;
					return Promise.resolve();
				}
				var self = this;
				this.partSearchLoading = true;
				this.error = '';
				return apiGet('/parts/usages', { q: query }).then(function (payload) {
					self.partUsages = payload;
					self.partUsageExpanded = {};
				}).catch(function (error) {
					self.error = error.message || String(error);
					self.partUsages = null;
					self.partUsageExpanded = {};
				}).finally(function () {
					self.partSearchLoading = false;
					self.debouncedSyncUrlFromState(true);
				});
			},
			onPartNumberInput: function () {
				if ((this.filters.partNumber || '').trim().length < 2) {
					this.partUsages = null;
					this.partUsageExpanded = {};
					this.partSearchLoading = false;
					this.debouncedSyncUrlFromState(true);
					return;
				}
				this.debouncedSearchPartUsages();
			},
			usageKey: function () {
				return Array.prototype.slice.call(arguments).join(':');
			},
			isUsageExpanded: function (key) {
				return !!this.partUsageExpanded[key];
			},
			toggleUsageExpanded: function (key) {
				this.partUsageExpanded[key] = !this.partUsageExpanded[key];
			},
			usageCountAssembliesInVariant: function (variant) {
				return (variant.assemblies || []).length;
			},
			usageCountAssembliesInModel: function (model) {
				var count = 0;
				(model.variants || []).forEach(function (variant) {
					count += (variant.assemblies || []).length;
				});
				return count;
			},
			usageCountAssembliesInFamily: function (family) {
				var count = 0;
				var self = this;
				(family.models || []).forEach(function (model) {
					count += self.usageCountAssembliesInModel(model);
				});
				return count;
			},
			usageCountAssembliesInBrand: function (brandGroup) {
				var count = 0;
				var self = this;
				(brandGroup.families || []).forEach(function (family) {
					count += self.usageCountAssembliesInFamily(family);
				});
				return count;
			},
			usagePlural: function (count, one, few, many) {
				var value = Math.abs(Number(count) || 0);
				var mod10 = value % 10;
				var mod100 = value % 100;
				if (mod100 >= 11 && mod100 <= 14) {
					return many;
				}
				if (mod10 === 1) {
					return one;
				}
				if (mod10 >= 2 && mod10 <= 4) {
					return few;
				}
				return many;
			},
			openPartUsage: function (brandGroup, variant, assembly) {
				var root = null;
				for (var i = 0; i < this.catalogRoots.length; i += 1) {
					if (this.catalogRoots[i].arib_code === brandGroup.root_arib) {
						root = this.catalogRoots[i];
						break;
					}
				}
				if (!root) {
					root = {
						arib_code: brandGroup.root_arib,
						name: brandGroup.root_name
					};
				}
				this.selected.root = root;
				this.selected.navStack = [];
				this.selected.navNode = null;
				this.selected.variant = variant;
				this.selected.assembly = {
					id: assembly.id,
					title: assembly.title
				};
				this.assemblies = [];
				this.diagramPayload = null;
				this.activeAssemblyPartId = null;
				this.step = 'diagram';
				this.pendingUrlPartId = null;
				this.loadDiagram().then(function () {
					self.debouncedSyncUrlFromState();
				});
			},
			assemblyRefsLabel: function (assembly) {
				if (!assembly || !assembly.refs || !assembly.refs.length) {
					return '';
				}
				return 'поз. ' + assembly.refs.join(', ');
			},
			selectRoot: function (item) {
				var self = this;
				this.selected.root = item;
				this.selected.navStack = [];
				this.selected.navNode = null;
				this.selected.variant = null;
				this.selected.assembly = null;
				this.variants = [];
				this.assemblies = [];
				this.diagramPayload = null;
				this.activeAssemblyPartId = null;
				this.step = 'browse';
				this.loadNav().then(function () {
					self.debouncedSyncUrlFromState();
				});
			},
			selectNavNode: function (node) {
				var self = this;
				this.selected.navStack.push(node);
				this.selected.navNode = node;
				this.selected.variant = null;
				this.selected.assembly = null;
				this.activeAssemblyPartId = null;
				this.variants = [];
				this.assemblies = [];
				this.diagramPayload = null;

				if (Number(node.child_count) > 0) {
					this.step = 'browse';
					return this.loadNav().then(function () {
						self.debouncedSyncUrlFromState();
					});
				}
				if (Number(node.variant_count) > 0) {
					this.step = 'variant';
					return this.loadVariantsForNav(node.id).then(function () {
						if (self.variants.length === 1) {
							return self.selectVariant(self.variants[0]);
						}
						self.debouncedSyncUrlFromState();
					});
				}
				this.step = 'browse';
				return this.loadNav().then(function () {
					self.debouncedSyncUrlFromState();
				});
			},
			selectVariant: function (variant) {
				this.selected.variant = variant;
				this.selected.assembly = null;
				this.activeAssemblyPartId = null;
				this.filters.assembly = '';
				this.assemblies = [];
				this.diagramPayload = null;
				this.step = 'assembly';
				var self = this;
				this.loadAssemblies().then(function () {
					self.debouncedSyncUrlFromState();
				});
			},
			selectAssembly: function (assembly) {
				this.selected.assembly = assembly;
				this.activeAssemblyPartId = null;
				this.pendingUrlPartId = null;
				this.step = 'diagram';
				var self = this;
				this.loadDiagram().then(function () {
					self.debouncedSyncUrlFromState();
				});
			},
			goToCrumb: function (crumb) {
				var self = this;
				if (crumb.step === 'root') {
					this.selected.root = null;
					this.selected.navStack = [];
					this.selected.navNode = null;
					this.selected.variant = null;
					this.selected.assembly = null;
					this.activeAssemblyPartId = null;
					this.navNodes = [];
					this.variants = [];
					this.assemblies = [];
					this.diagramPayload = null;
					this.step = 'root';
					this.debouncedSyncUrlFromState();
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
					this.activeAssemblyPartId = null;
					this.variants = [];
					this.assemblies = [];
					this.diagramPayload = null;
					this.step = 'browse';
					this.loadNav().then(function () {
						self.debouncedSyncUrlFromState();
					});
					return;
				}
				if (crumb.step === 'variant') {
					this.selected.assembly = null;
					this.activeAssemblyPartId = null;
					this.diagramPayload = null;
					this.step = 'assembly';
					this.loadAssemblies().then(function () {
						self.debouncedSyncUrlFromState();
					});
					return;
				}
				if (crumb.step === 'assembly') {
					if (this.step === 'diagram') {
						this.selected.assembly = null;
						this.activeAssemblyPartId = null;
						this.pendingUrlPartId = null;
						this.partOffersOpenId = null;
						this.partOffersByPartId = {};
						this.teardownDiagramObserver();
						this.diagramPayload = null;
						this.step = 'assembly';
						this.loadAssemblies().then(function () {
							self.debouncedSyncUrlFromState();
						});
						return;
					}
					if (!this.selected.assembly) {
						this.step = 'assembly';
						this.loadAssemblies().then(function () {
							self.debouncedSyncUrlFromState();
						});
						return;
					}
					this.activeAssemblyPartId = null;
					this.step = 'diagram';
					this.loadDiagram().then(function () {
						self.debouncedSyncUrlFromState();
					});
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
			partOffersEntry: function (assemblyPartId) {
				return this.partOffersByPartId[assemblyPartId] || {
					loading: false,
					error: '',
					products: [],
					emptyMessage: '',
					loaded: false
				};
			},
			togglePartOffers: function (part) {
				if (!part || !part.assembly_part_id) {
					return;
				}
				var id = part.assembly_part_id;
				if (this.partOffersOpenId === id) {
					this.partOffersOpenId = null;
					return;
				}
				this.partOffersOpenId = id;
				this.loadPartOffers(part);
			},
			loadPartOffers: function (part) {
				var id = part.assembly_part_id;
				var existing = this.partOffersByPartId[id];
				if (existing && existing.loaded) {
					var self = this;
					this.$nextTick(function () {
						self.syncPartOffersBasketUi();
					});
					return;
				}
				var self = this;
				this.partOffersByPartId = Object.assign({}, this.partOffersByPartId, {
					[id]: {
						loading: true,
						error: '',
						products: [],
						emptyMessage: '',
						loaded: false
					}
				});
				var brand = '';
				if (this.selected.root) {
					brand = this.selected.root.name || this.selected.root.arib_code || '';
				}
				var url = new URL('/ajax/mf_oem_part_offers.php', window.location.origin);
				url.searchParams.set('partNumber', part.part_number || '');
				if (brand) {
					url.searchParams.set('brand', brand);
				}
				fetch(url.toString(), { credentials: 'same-origin' })
					.then(function (response) {
						return response.json().then(function (payload) {
							if (!response.ok || !payload || !payload.ok) {
								throw new Error((payload && payload.error) || ('HTTP ' + response.status));
							}
							return payload;
						});
					})
					.then(function (payload) {
						self.partOffersByPartId = Object.assign({}, self.partOffersByPartId, {
							[id]: {
								loading: false,
								error: '',
								products: payload.products || [],
								emptyMessage: payload.empty_message || '',
								loaded: true
							}
						});
						self.$nextTick(function () {
							self.syncPartOffersBasketUi();
						});
					})
					.catch(function (error) {
						self.partOffersByPartId = Object.assign({}, self.partOffersByPartId, {
							[id]: {
								loading: false,
								error: error.message || 'Не удалось загрузить предложения.',
								products: [],
								emptyMessage: '',
								loaded: true
							}
						});
					});
			},
			syncPartOffersBasketUi: function () {
				if (typeof window.__mfSyncBasketState === 'function') {
					window.__mfSyncBasketState();
				}
			},
			bindPartOffersUiHandlers: function () {
				if (root.__mfOemOffersUiBound) {
					return;
				}
				root.__mfOemOffersUiBound = true;
				root.addEventListener('click', function (event) {
					var minusBtn = event.target && event.target.closest ? event.target.closest('.js-mf-qty-minus') : null;
					if (minusBtn && root.contains(minusBtn)) {
						event.preventDefault();
						event.stopPropagation();
						var minusWrap = minusBtn.closest('.mf-search-qty');
						var minusInput = minusWrap ? minusWrap.querySelector('.js-mf-qty-input') : null;
						if (minusInput) {
							var minusVal = parseInt(minusInput.value || '1', 10);
							if (!isFinite(minusVal) || minusVal < 1) {
								minusVal = 1;
							}
							minusInput.value = String(Math.max(1, minusVal - 1));
						}
						return;
					}
					var plusBtn = event.target && event.target.closest ? event.target.closest('.js-mf-qty-plus') : null;
					if (plusBtn && root.contains(plusBtn)) {
						event.preventDefault();
						event.stopPropagation();
						var plusWrap = plusBtn.closest('.mf-search-qty');
						var plusInput = plusWrap ? plusWrap.querySelector('.js-mf-qty-input') : null;
						if (plusInput) {
							var plusVal = parseInt(plusInput.value || '1', 10);
							if (!isFinite(plusVal) || plusVal < 1) {
								plusVal = 1;
							}
							var maxQty = parseFloat(plusWrap.getAttribute('data-max-qty') || '0');
							var nextVal = plusVal + 1;
							if (isFinite(maxQty) && maxQty > 0) {
								nextVal = Math.min(nextVal, Math.max(1, Math.floor(maxQty)));
							}
							plusInput.value = String(nextVal);
						}
					}
				}, true);
			},
			hotspotStyle: function (hotspot) {
				var coord = this.diagramCoordSpace;
				var coordWidth = Number(coord.width || 0);
				var coordHeight = Number(coord.height || 0);
				if (!coordWidth || !coordHeight) {
					return {};
				}
				var x = Number(hotspot.x || 0);
				var y = Number(hotspot.y || 0);
				var width = Math.max(Number(hotspot.width || 0), 1);
				var height = Math.max(Number(hotspot.height || 0), 1);
				return {
					left: (x / coordWidth * 100) + '%',
					top: (y / coordHeight * 100) + '%',
					width: (width / coordWidth * 100) + '%',
					height: (height / coordHeight * 100) + '%'
				};
			},
			teardownDiagramObserver: function () {
				if (this.diagramResizeObserver) {
					this.diagramResizeObserver.disconnect();
					this.diagramResizeObserver = null;
				}
			},
			setupDiagramObserver: function () {
				var self = this;
				this.teardownDiagramObserver();
				var img = this.$refs.diagramImage;
				if (!img) {
					return;
				}
				if (typeof ResizeObserver === 'undefined') {
					this.syncDiagramDisplaySize();
					return;
				}
				this.diagramResizeObserver = new ResizeObserver(function () {
					self.debouncedSyncDiagramDisplay();
				});
				this.diagramResizeObserver.observe(img);
				var viewport = this.$refs.diagramViewport;
				if (viewport) {
					this.diagramResizeObserver.observe(viewport);
				}
				this.syncDiagramDisplaySize();
			},
			syncDiagramDisplaySize: function () {
				var img = this.$refs.diagramImage;
				if (!img) {
					return;
				}
				this.diagramDisplay = {
					width: img.clientWidth,
					height: img.clientHeight
				};
				this.clampDiagramPan();
			},
			focusPart: function (assemblyPartId, options) {
				if (!assemblyPartId) {
					return;
				}
				options = options || {};
				this.activeAssemblyPartId = assemblyPartId;
				var self = this;
				var scrollTarget = options.scrollTarget || 'part';
				this.$nextTick(function () {
					if (scrollTarget === 'diagram') {
						self.scrollToDiagramHotspot(assemblyPartId);
					} else {
						self.scrollToPartRow(assemblyPartId);
					}
				});
				if (!options.skipUrl) {
					this.debouncedSyncUrlFromState();
				}
			},
			clampDiagramPan: function () {
				var viewport = this.$refs.diagramViewport;
				if (!viewport) {
					return;
				}
				var viewportWidth = viewport.clientWidth;
				var viewportHeight = viewport.clientHeight;
				if (!viewportWidth || !viewportHeight) {
					return;
				}
				var contentWidth = viewportWidth * this.diagramZoom;
				var contentHeight = viewportHeight * this.diagramZoom;
				var minX = Math.min(0, viewportWidth - contentWidth);
				var minY = Math.min(0, viewportHeight - contentHeight);
				this.diagramPan.x = Math.max(minX, Math.min(0, this.diagramPan.x));
				this.diagramPan.y = Math.max(minY, Math.min(0, this.diagramPan.y));
			},
			diagramZoomBy: function (factor) {
				var viewport = this.$refs.diagramViewport;
				if (!viewport) {
					return;
				}
				var oldZoom = this.diagramZoom;
				var newZoom = Math.max(1, Math.min(4, Number((oldZoom * factor).toFixed(4))));
				if (Math.abs(newZoom - oldZoom) < 0.001) {
					return;
				}
				var centerX = viewport.clientWidth / 2;
				var centerY = viewport.clientHeight / 2;
				var contentX = (centerX - this.diagramPan.x) / oldZoom;
				var contentY = (centerY - this.diagramPan.y) / oldZoom;
				this.diagramZoom = newZoom;
				this.diagramPan.x = centerX - contentX * newZoom;
				this.diagramPan.y = centerY - contentY * newZoom;
				this.clampDiagramPan();
			},
			diagramZoomIn: function () {
				this.diagramZoomBy(1.25);
			},
			diagramZoomOut: function () {
				this.diagramZoomBy(1 / 1.25);
			},
			onDiagramPanPointerDown: function (event) {
				if (event.button !== 0) {
					return;
				}
				if (event.target.closest('.mf-oem-hotspot') || event.target.closest('.mf-oem-diagram-zoom-controls')) {
					return;
				}
				this.diagramPanDragging = true;
				this.diagramPanDidDrag = false;
				this.diagramPanPointerId = event.pointerId;
				this.diagramPanStart = {
					x: event.clientX,
					y: event.clientY,
					panX: this.diagramPan.x,
					panY: this.diagramPan.y
				};
				if (event.currentTarget.setPointerCapture) {
					event.currentTarget.setPointerCapture(event.pointerId);
				}
			},
			onDiagramPanPointerMove: function (event) {
				if (!this.diagramPanDragging || event.pointerId !== this.diagramPanPointerId || !this.diagramPanStart) {
					return;
				}
				var deltaX = event.clientX - this.diagramPanStart.x;
				var deltaY = event.clientY - this.diagramPanStart.y;
				if (Math.abs(deltaX) > 3 || Math.abs(deltaY) > 3) {
					this.diagramPanDidDrag = true;
				}
				this.diagramPan.x = this.diagramPanStart.panX + deltaX;
				this.diagramPan.y = this.diagramPanStart.panY + deltaY;
				this.clampDiagramPan();
			},
			onDiagramPanPointerUp: function (event) {
				if (event.pointerId !== this.diagramPanPointerId) {
					return;
				}
				this.diagramPanDragging = false;
				this.diagramPanPointerId = null;
				this.diagramPanStart = null;
			},
			centerDiagramOnHotspot: function (assemblyPartId) {
				var viewport = this.$refs.diagramViewport;
				var hotspot = root.querySelector('.mf-oem-hotspot[data-part-id="' + assemblyPartId + '"]');
				if (!viewport || !hotspot) {
					return;
				}
				var viewportRect = viewport.getBoundingClientRect();
				var hotspotRect = hotspot.getBoundingClientRect();
				var deltaX = (hotspotRect.left + hotspotRect.width / 2) - (viewportRect.left + viewportRect.width / 2);
				var deltaY = (hotspotRect.top + hotspotRect.height / 2) - (viewportRect.top + viewportRect.height / 2);
				this.diagramPan.x -= deltaX;
				this.diagramPan.y -= deltaY;
				this.clampDiagramPan();
			},
			scrollToPartRow: function (assemblyPartId) {
				var row = root.querySelector('.mf-oem-part-row[data-part-id="' + assemblyPartId + '"]');
				if (row) {
					row.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
				}
			},
			scrollToDiagramHotspot: function (assemblyPartId) {
				var stage = root.querySelector('.mf-oem-diagram-stage');
				if (stage) {
					stage.scrollIntoView({ block: 'start', behavior: 'smooth' });
				}
				var self = this;
				window.setTimeout(function () {
					self.centerDiagramOnHotspot(assemblyPartId);
				}, 280);
			},
			onDiagramImageLoad: function (event) {
				var img = event.target;
				this.diagramNatural.width = img.naturalWidth;
				this.diagramNatural.height = img.naturalHeight;
				var self = this;
				this.$nextTick(function () {
					self.setupDiagramObserver();
					if (self.pendingUrlPartId) {
						self.applyPendingUrlPart().then(function () {
							self.syncUrlFromState(true);
						});
					}
				});
			}
		}
	}).mount(root);
})();
