from django.urls import path

from .views import HomePageView, health

urlpatterns = [
    path("", HomePageView.as_view(), name="home"),
    path("health/", health, name="health"),
]
