"""Public pages; participant booking is not implemented in Phase 1."""
from django.http import JsonResponse
from django.views.generic import TemplateView


class HomePageView(TemplateView):
    template_name = "pages/home.html"


def health(request):
    """Lightweight liveness endpoint, not a database readiness check."""
    return JsonResponse({"status": "ok"})
